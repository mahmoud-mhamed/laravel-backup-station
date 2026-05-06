<?php

namespace MahmoudMhamed\BackupStation;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MahmoudMhamed\BackupStation\Notifications\BackupNotifier;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class BackupStationService
{
    /**
     * The disk name backups live on. Defaults to the app's
     * filesystems.default when not explicitly set.
     */
    public function diskName(): string
    {
        $disk = config('backup-station.storage.disk');
        return $disk ?: (string) config('filesystems.default', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Whether the current disk is a remote/network filesystem where
     * per-file existence checks are expensive (each one is a HEAD request).
     */
    public function isRemoteDisk(): bool
    {
        $cfg = config("filesystems.disks.{$this->diskName()}", []);
        $driver = $cfg['driver'] ?? null;
        return !in_array($driver, ['local', null], true);
    }

    /**
     * Folder/prefix on the disk where backups live.
     */
    public function storageRoot(): string
    {
        return trim((string) config('backup-station.storage.path', 'backup-station'), '/');
    }

    public function pathFor(string $filename): string
    {
        $root = $this->storageRoot();
        return ($root === '' ? '' : $root . '/') . ltrim($filename, '/');
    }

    public function metadataPath(): string
    {
        return $this->pathFor('backups.json');
    }

    /* -------------------------------------------------------------------- */
    /* Backup runner                                                         */
    /* -------------------------------------------------------------------- */

    /**
     * Hard cap on total bytes the package may store on the disk.
     * Returns 0 when no quota is configured.
     */
    public function totalQuotaBytes(): int
    {
        return ((int) config('backup-station.import.total_quota_kb', 0)) * 1024;
    }

    public function assertWithinQuota(int $incomingBytes = 0): void
    {
        $cap = $this->totalQuotaBytes();
        if ($cap <= 0) return;

        $current = $this->totalSize();
        if (($current + $incomingBytes) > $cap) {
            throw new RuntimeException(sprintf(
                'Storage quota exceeded: would use %s, cap is %s.',
                $this->formatBytes($current + $incomingBytes),
                $this->formatBytes($cap)
            ));
        }
    }

    /**
     * Per-run overrides applied during backupConnection(). Set via
     * runBackup($conn, $note, $overrides) and consumed by the dump methods.
     *
     * Keys:
     *   tables: string[]   — explicit include list (overrides config)
     *   mode:   'full'|'structure'|'data'
     */
    /**
     * Per-run overrides:
     *   tables_structure: string[] — tables to dump CREATE TABLE for
     *   tables_data:      string[] — tables to dump INSERT rows for
     *   tables / mode:    legacy — converted to the two arrays above
     *   exclude:          string[] — global exclude (mysqldump --ignore-table)
     */
    protected array $runOverrides = [
        'tables_structure' => null,
        'tables_data' => null,
        'tables' => [],
        'exclude' => [],
        'mode' => 'full',
    ];

    public function runBackup(?string $connection = null, ?string $note = null, array $overrides = []): array
    {
        $this->runOverrides = array_merge($this->runOverrides, $overrides);

        $this->assertWithinQuota();

        $connections = $connection
            ? [$connection]
            : (config('backup-station.connections') ?: [config('database.default')]);

        $created = [];

        foreach (array_filter($connections) as $conn) {
            $started = microtime(true);
            try {
                $created[] = $this->backupConnection($conn, $note);
            } catch (Throwable $e) {
                $this->appendMetadata([
                    'id' => (string) Str::uuid(),
                    'connection' => $conn,
                    'database' => config("database.connections.{$conn}.database"),
                    'driver' => config("database.connections.{$conn}.driver"),
                    'disk' => $this->diskName(),
                    'filename' => null,
                    'path' => null,
                    'size' => 0,
                    'created_at' => now()->toIso8601String(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'note' => $note,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'pinned' => false,
                    'monthly_keep' => false,
                ]);

                $this->notifier()->send('failure', [
                    'connection' => $conn,
                    'database' => config("database.connections.{$conn}.database"),
                    'driver' => config("database.connections.{$conn}.driver"),
                    'duration' => $this->formatDuration((int) round((microtime(true) - $started) * 1000)),
                    'tables' => $this->describeTableSelection(),
                    'mode' => $this->describeMode(),
                    'note' => $note,
                    'error' => $e->getMessage(),
                    'time' => now()->toDateTimeString(),
                ]);

                throw $e;
            }
        }

        $this->applyRetentionPolicy();

        return $created;
    }

    /**
     * List the tables of a configured Laravel database connection.
     * Used by the dashboard's "Run Backup" dialog to show a checklist.
     */
    /**
     * Live size in bytes of the source database.
     * Returns ['size' => int, 'database' => string, 'driver' => string].
     */
    public function databaseSize(?string $connection = null): array
    {
        $connection = $connection ?: config('database.default');
        $cfg = config("database.connections.{$connection}");
        if (!$cfg) {
            return ['size' => 0, 'database' => null, 'driver' => null, 'connection' => $connection];
        }

        $driver = $cfg['driver'] ?? 'mysql';
        $database = $cfg['database'] ?? null;
        $size = 0;

        try {
            $size = match ($driver) {
                'mysql', 'mariadb' => (int) (DB::connection($connection)->selectOne(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
                       FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()'
                )->bytes ?? 0),
                'pgsql', 'postgres' => (int) (DB::connection($connection)->selectOne(
                    'SELECT pg_database_size(current_database()) AS bytes'
                )->bytes ?? 0),
                'sqlite' => is_file((string) $database) ? (int) filesize((string) $database) : 0,
                default => 0,
            };
        } catch (Throwable) {
            $size = 0;
        }

        return [
            'size' => $size,
            'database' => $database,
            'driver' => $driver,
            'connection' => $connection,
        ];
    }

    public function listTables(?string $connection = null): array
    {
        $connection = $connection ?: config('database.default');
        $cfg = config("database.connections.{$connection}");
        if (!$cfg) {
            throw new RuntimeException("Connection [{$connection}] is not configured.");
        }

        $driver = $cfg['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql', 'mariadb' => $this->listMysqlTables($connection),
            'pgsql', 'postgres' => $this->listPostgresTables($connection),
            'sqlite' => $this->listSqliteTables($connection),
            default => throw new RuntimeException("Unsupported driver [{$driver}]."),
        };
    }

    protected function listMysqlTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            'SELECT TABLE_NAME AS table_name,
                    TABLE_ROWS AS table_rows,
                    COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS size_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"
             ORDER BY TABLE_NAME'
        );
        return array_map(fn ($r) => [
            'name' => $r->table_name,
            'rows' => (int) ($r->table_rows ?? 0),
            'size' => (int) ($r->size_bytes ?? 0),
        ], $rows);
    }

    protected function listPostgresTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT c.relname AS table_name,
                    COALESCE(c.reltuples, 0)::bigint AS table_rows,
                    pg_total_relation_size(c.oid) AS size_bytes
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE c.relkind = 'r' AND n.nspname = current_schema()
              ORDER BY c.relname"
        );
        return array_map(fn ($r) => [
            'name' => $r->table_name,
            'rows' => (int) ($r->table_rows ?? 0),
            'size' => (int) ($r->size_bytes ?? 0),
        ], $rows);
    }

    protected function listSqliteTables(string $connection): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        $db = DB::connection($connection);
        $out = [];
        foreach ($rows as $r) {
            $count = 0;
            try {
                $count = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM "' . str_replace('"', '""', $r->name) . '"')->c ?? 0);
            } catch (Throwable) {
                // virtual / shadow table — skip count
            }
            $out[] = ['name' => $r->name, 'rows' => $count, 'size' => 0];
        }
        return $out;
    }

    public function isEncryptionEnabled(): bool
    {
        return (bool) config('backup-station.encryption.enabled')
            && (string) config('backup-station.encryption.password', '') !== '';
    }

    /**
     * Resolve the archive format used for new backups.
     * Returns: 'zip' | 'gzip' | 'none' | 'encrypted-zip'.
     */
    public function archiveFormat(): string
    {
        if ($this->isEncryptionEnabled()) return 'encrypted-zip';

        $archive = config('backup-station.archive');
        if (in_array($archive, ['zip', 'gzip', 'none'], true)) return $archive;

        // Backward compat with the old `compress` boolean.
        return config('backup-station.compress', true) ? 'gzip' : 'none';
    }

    protected function extensionFor(string $format): string
    {
        return match ($format) {
            'gzip' => '.sql.gz',
            'zip', 'encrypted-zip' => '.sql.zip',
            default => '.sql',
        };
    }

    protected function backupConnection(string $connection, ?string $note = null): array
    {
        $startedAt = microtime(true);

        $config = config("database.connections.{$connection}");

        if (!$config) {
            throw new RuntimeException("Connection [{$connection}] is not configured.");
        }

        $driver = $config['driver'] ?? 'mysql';
        $database = $config['database'] ?? 'database';

        $format = $this->archiveFormat();
        // Stream gzip during the dump only when the final format is gzip;
        // for zip/encrypted-zip we want a raw .sql we can wrap afterwards.
        $compressDuringDump = ($format === 'gzip');

        $extension = $this->extensionFor($format);
        $filename = $this->buildFilename($connection, $database) . $extension;

        // Always dump to a local temp file first. The (optional) zip wrap
        // also happens locally; only the final artifact is uploaded.
        $rawTemp = $this->newTempPath('raw_' . $filename);
        $uploadTemp = $rawTemp;

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($config, $rawTemp, $compressDuringDump),
                'pgsql', 'postgres' => $this->dumpPostgres($config, $rawTemp, $compressDuringDump),
                'sqlite' => $this->dumpSqlite($config, $rawTemp, $compressDuringDump),
                default => throw new RuntimeException("Unsupported driver [{$driver}]."),
            };

            if (!file_exists($rawTemp) || filesize($rawTemp) === 0) {
                throw new RuntimeException("Backup file was not created or is empty.");
            }

            if ($format === 'zip' || $format === 'encrypted-zip') {
                $zippedTemp = $this->newTempPath($filename);
                $innerName = preg_replace('/\.zip$/i', '', $filename); // ".sql"
                if ($format === 'encrypted-zip') {
                    $this->encryptZip($rawTemp, $zippedTemp, $innerName, (string) config('backup-station.encryption.password'));
                } else {
                    $this->plainZip($rawTemp, $zippedTemp, $innerName);
                }
                $uploadTemp = $zippedTemp;
            }

            $finalPath = $this->moveIntoStorage($uploadTemp, $filename);
            $size = $this->size($filename);
        } finally {
            if (file_exists($rawTemp)) @unlink($rawTemp);
            if ($uploadTemp !== $rawTemp && file_exists($uploadTemp)) @unlink($uploadTemp);
        }

        $entry = [
            'id' => (string) Str::uuid(),
            'connection' => $connection,
            'database' => $database,
            'driver' => $driver,
            'disk' => $this->diskName(),
            'filename' => $filename,
            'path' => $finalPath,
            'size' => $size,
            'created_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'note' => $note,
            'status' => 'success',
            'error' => null,
            'pinned' => false,
            'monthly_keep' => $this->shouldFlagAsMonthlyKeep(),
            'archive' => $format,
            'encrypted' => $format === 'encrypted-zip',
        ];

        $this->appendMetadata($entry);

        $this->notifier()->send('success', [
            'filename' => $filename,
            'connection' => $connection,
            'database' => $database,
            'driver' => $driver,
            'size' => $this->formatBytes((int) $entry['size']),
            'duration' => $this->formatDuration((int) $entry['duration_ms']),
            'tables' => $this->describeTableSelection(),
            'mode' => $this->describeMode(),
            'note' => $note,
            'time' => now()->toDateTimeString(),
        ]);

        return $entry;
    }

    /**
     * Short human description of the table-selection currently in effect.
     * E.g. "users + 4 more (structure + data)" or "all tables".
     */
    protected function describeTableSelection(): ?string
    {
        [$structure, $data] = $this->resolveTableSelection();
        $all = array_values(array_unique(array_merge($structure, $data)));
        if (!$all) return null;
        $count = count($all);
        $preview = implode(', ', array_slice($all, 0, 3));
        return $count > 3 ? "{$preview}, +" . ($count - 3) . ' more' : $preview;
    }

    protected function describeMode(): ?string
    {
        [$structure, $data] = $this->resolveTableSelection();
        if (!$structure && !$data) return null;

        $both = array_intersect($structure, $data);
        $structureOnly = array_diff($structure, $data);
        $dataOnly = array_diff($data, $structure);

        $parts = [];
        if (count($both)) $parts[] = count($both) . ' full';
        if (count($structureOnly)) $parts[] = count($structureOnly) . ' structure-only';
        if (count($dataOnly)) $parts[] = count($dataOnly) . ' data-only';

        return $parts ? implode(' · ', $parts) : null;
    }

    protected function notifier(): BackupNotifier
    {
        return app(BackupNotifier::class);
    }

    /* -------------------------------------------------------------------- */
    /* Storage adapter                                                       */
    /* -------------------------------------------------------------------- */

    protected function newTempPath(string $filename): string
    {
        $dir = sys_get_temp_dir() . '/backup-station';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . uniqid('bs_', true) . '_' . $filename;
    }

    /**
     * Move a freshly dumped temp file into the configured storage location.
     * Returns the final path (absolute for local, disk-relative for remote).
     */
    protected function moveIntoStorage(string $tempPath, string $filename): string
    {
        $stream = fopen($tempPath, 'rb');
        try {
            $this->disk()->writeStream($this->pathFor($filename), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return $this->pathFor($filename);
    }

    public function fileExists(string $filename): bool
    {
        return $this->disk()->exists($this->pathFor($filename));
    }

    public function size(string $filename): int
    {
        return $this->fileExists($filename)
            ? (int) $this->disk()->size($this->pathFor($filename))
            : 0;
    }

    public function deleteFile(string $filename): void
    {
        if ($this->fileExists($filename)) {
            $this->disk()->delete($this->pathFor($filename));
        }
    }

    public function moveFile(string $from, string $to): void
    {
        $this->disk()->move($this->pathFor($from), $this->pathFor($to));
    }

    public function downloadResponse(array $entry): StreamedResponse
    {
        $filename = $entry['filename'] ?? null;
        if (!$filename || !$this->fileExists($filename)) {
            throw new RuntimeException('Backup file not found.');
        }

        return $this->disk()->download($this->pathFor($filename), $filename);
    }

    /* -------------------------------------------------------------------- */
    /* Database dump implementations                                         */
    /* -------------------------------------------------------------------- */

    protected function dumpMysql(array $config, string $output, bool $compress): void
    {
        $bin = $this->resolveBinary('mysqldump');
        $opts = (array) config('backup-station.mysqldump_options', []);
        $database = $config['database'];
        $defaultsFile = $this->writeMysqlDefaultsFile($config);

        [$structureTables, $dataTables, $exclude] = $this->resolveTableSelection($config['connection'] ?? null);

        $excludeOpts = [];
        foreach ($exclude as $t) {
            $excludeOpts[] = '--ignore-table=' . $database . '.' . $t;
        }

        $baseArgs = array_merge(
            [$bin, '--defaults-extra-file=' . $defaultsFile],
            $opts,
            $excludeOpts,
            [
                '-h', $config['host'] ?? '127.0.0.1',
                '-P', (string) ($config['port'] ?? 3306),
            ]
        );

        // Build the table groupings.
        $bothTables = array_values(array_intersect($structureTables, $dataTables));
        $structureOnly = array_values(array_diff($structureTables, $dataTables));
        $dataOnly = array_values(array_diff($dataTables, $structureTables));

        if (empty($bothTables) && empty($structureOnly) && empty($dataOnly)) {
            // No selection ⇒ full database dump (backward compat).
            try {
                $this->withDumpOutput($output, $compress, function (callable $writer) use ($baseArgs, $database) {
                    $this->streamDumpCommand(array_merge($baseArgs, [$database]), $writer);
                });
            } finally {
                @unlink($defaultsFile);
            }
            return;
        }

        try {
            $this->withDumpOutput($output, $compress, function (callable $writer) use ($baseArgs, $database, $bothTables, $structureOnly, $dataOnly) {
                $writer("-- Backup Station — multi-pass MySQL dump\n");

                if ($bothTables) {
                    $writer("\n-- ----- Structure + Data -----\n");
                    $this->streamDumpCommand(array_merge($baseArgs, [$database], $bothTables), $writer);
                }
                if ($structureOnly) {
                    $writer("\n-- ----- Structure only -----\n");
                    $this->streamDumpCommand(array_merge($baseArgs, ['--no-data'], [$database], $structureOnly), $writer);
                }
                if ($dataOnly) {
                    $writer("\n-- ----- Data only -----\n");
                    $this->streamDumpCommand(array_merge($baseArgs, ['--no-create-info'], [$database], $dataOnly), $writer);
                }
            });
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * Resolve [structureTables[], dataTables[], exclude[]] for the run.
     *
     * Priority:
     *   1. Explicit `tables_structure` + `tables_data` arrays (UI per-row).
     *   2. Legacy `tables` whitelist + `mode` (full/structure/data).
     *   3. Empty selection ⇒ caller falls back to whole-DB dump.
     */
    protected function resolveTableSelection(?string $connection = null): array
    {
        $sanitize = function (array $list): array {
            $out = [];
            foreach ($list as $t) {
                $t = trim((string) $t);
                if ($t === '' || !preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
                $out[] = $t;
            }
            return array_values(array_unique($out));
        };

        $structure = $this->runOverrides['tables_structure'] ?? null;
        $data = $this->runOverrides['tables_data'] ?? null;

        if ($structure !== null || $data !== null) {
            return [
                $sanitize((array) ($structure ?? [])),
                $sanitize((array) ($data ?? [])),
                $sanitize((array) ($this->runOverrides['exclude'] ?? [])),
            ];
        }

        // Legacy fallback: tables[] + mode
        $legacyTables = $sanitize((array) ($this->runOverrides['tables'] ?? []));
        $mode = $this->runOverrides['mode'] ?? 'full';

        if (empty($legacyTables)) {
            // No selection at all — caller will fall back to a whole-DB dump.
            return [[], [], $sanitize((array) ($this->runOverrides['exclude'] ?? []))];
        }

        return match ($mode) {
            'structure' => [$legacyTables, [], $sanitize((array) ($this->runOverrides['exclude'] ?? []))],
            'data' => [[], $legacyTables, $sanitize((array) ($this->runOverrides['exclude'] ?? []))],
            default => [$legacyTables, $legacyTables, $sanitize((array) ($this->runOverrides['exclude'] ?? []))],
        };
    }

    /**
     * Write a temporary MySQL options file (chmod 0600) containing the
     * username/password. This keeps the credentials out of `ps aux`,
     * which would expose them to every user on the host when passed on
     * the command line via `-pSECRET`.
     */
    protected function writeMysqlDefaultsFile(array $config): string
    {
        $dir = sys_get_temp_dir() . '/backup-station';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $path = $dir . '/.my-' . bin2hex(random_bytes(8)) . '.cnf';

        $user = (string) ($config['username'] ?? '');
        $pass = (string) ($config['password'] ?? '');

        $contents = "[client]\n"
            . 'user = "' . addslashes($user) . "\"\n"
            . 'password = "' . addslashes($pass) . "\"\n";

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        return $path;
    }

    protected function dumpPostgres(array $config, string $output, bool $compress): void
    {
        $bin = $this->resolveBinary('pg_dump');
        [$structureTables, $dataTables, $exclude] = $this->resolveTableSelection($config['connection'] ?? null);

        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];

        $baseArgs = [
            $bin,
            '-h', $config['host'] ?? '127.0.0.1',
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? 'postgres'),
            '-d', $config['database'],
            '--no-owner',
            '--no-privileges',
        ];
        foreach ($exclude as $t) $baseArgs[] = '--exclude-table=' . $t;

        $bothTables = array_values(array_intersect($structureTables, $dataTables));
        $structureOnly = array_values(array_diff($structureTables, $dataTables));
        $dataOnly = array_values(array_diff($dataTables, $structureTables));

        if (empty($bothTables) && empty($structureOnly) && empty($dataOnly)) {
            $this->withDumpOutput($output, $compress, function (callable $writer) use ($baseArgs, $env) {
                $this->streamDumpCommand($baseArgs, $writer, $env);
            });
            return;
        }

        $tableArgs = fn (array $tables) => array_reduce($tables, fn ($carry, $t) => array_merge($carry, ['-t', $t]), []);

        $this->withDumpOutput($output, $compress, function (callable $writer) use ($baseArgs, $env, $bothTables, $structureOnly, $dataOnly, $tableArgs) {
            $writer("-- Backup Station — multi-pass PostgreSQL dump\n");

            if ($bothTables) {
                $writer("\n-- ----- Structure + Data -----\n");
                $this->streamDumpCommand(array_merge($baseArgs, $tableArgs($bothTables)), $writer, $env);
            }
            if ($structureOnly) {
                $writer("\n-- ----- Structure only -----\n");
                $this->streamDumpCommand(array_merge($baseArgs, ['--schema-only'], $tableArgs($structureOnly)), $writer, $env);
            }
            if ($dataOnly) {
                $writer("\n-- ----- Data only -----\n");
                $this->streamDumpCommand(array_merge($baseArgs, ['--data-only'], $tableArgs($dataOnly)), $writer, $env);
            }
        });
    }

    protected function dumpSqlite(array $config, string $output, bool $compress): void
    {
        $bin = $this->resolveBinary('sqlite3');
        $dbPath = $config['database'] ?? '';

        if (!file_exists($dbPath)) {
            throw new RuntimeException("SQLite database not found at [{$dbPath}].");
        }

        $this->withDumpOutput($output, $compress, function (callable $writer) use ($bin, $dbPath) {
            $this->streamDumpCommand([$bin, $dbPath, '.dump'], $writer);
        });
    }

    /**
     * Resolve a binary by name. Looks in (1) the configured path,
     * (2) the system PATH via `which`/`where`, then (3) the
     * `binary_paths` lookup table.
     *
     * Throws if nothing is found, with a helpful message including the
     * places that were searched so the user knows what to install or
     * configure.
     */
    public function resolveBinary(string $name): string
    {
        $cached =& self::$resolvedBinaries[$name];
        if ($cached !== null) {
            return $cached;
        }

        $configured = config("backup-station.binaries.{$name}");
        if ($configured && $this->isExecutable($configured)) {
            return $cached = $configured;
        }

        if ($found = $this->lookupOnPath($name)) {
            return $cached = $found;
        }

        $candidates = (array) config("backup-station.binary_paths.{$name}", []);
        foreach ($candidates as $path) {
            if ($this->isExecutable($path)) {
                return $cached = $path;
            }
        }

        $searched = array_merge(
            $configured ? ["configured: {$configured}"] : [],
            ['system PATH'],
            $candidates
        );

        throw new RuntimeException(
            "Unable to locate `{$name}` binary. Install it or set BACKUP_STATION_"
            . strtoupper($name) . " in your .env. Searched:\n  - "
            . implode("\n  - ", $searched)
        );
    }

    /** @var array<string,string|null> */
    protected static array $resolvedBinaries = [];

    protected function isExecutable(string $path): bool
    {
        if ($path === '' || !file_exists($path)) {
            return false;
        }
        // On Windows is_executable() is unreliable for .exe files — file_exists is enough.
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }
        return is_executable($path);
    }

    protected function lookupOnPath(string $name): ?string
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $cmd = $isWindows ? ['where', $name] : ['command', '-v', $name];

        try {
            $process = new Process($cmd);
            $process->run();
            if ($process->isSuccessful()) {
                $first = trim(strtok($process->getOutput(), "\n") ?: '');
                if ($first !== '' && $this->isExecutable($first)) {
                    return $first;
                }
            }
        } catch (Throwable) {
            // ignore — fall back to candidates
        }

        // `which` as a final attempt (POSIX without `command` builtin shells)
        if (!$isWindows) {
            try {
                $process = new Process(['which', $name]);
                $process->run();
                if ($process->isSuccessful()) {
                    $first = trim(strtok($process->getOutput(), "\n") ?: '');
                    if ($first !== '' && $this->isExecutable($first)) {
                        return $first;
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return null;
    }

    /**
     * Run one dump command and pipe its stdout through $writer (a callable
     * that accepts chunks of bytes). Used by both single-pass dumps and the
     * multi-pass per-table-mode flow.
     */
    protected function streamDumpCommand(array $cmd, callable $writer, array $env = []): void
    {
        $timeout = (int) config('backup-station.timeout', 1800);
        $process = new Process($cmd);
        $process->setTimeout($timeout);

        if ($env) {
            $process->setEnv(array_replace($_SERVER ?: [], $env));
        }

        $errorBuffer = '';
        $process->start();
        foreach ($process as $type => $data) {
            if ($type === Process::OUT) {
                $writer($data);
            } else {
                $errorBuffer .= $data;
            }
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Dump command failed: ' . trim($errorBuffer ?: $process->getErrorOutput()));
        }
    }

    /**
     * Open the output file (compressed or not) and call $body with a writer.
     * Cleans up the file if the body throws.
     */
    protected function withDumpOutput(string $output, bool $compress, callable $body): void
    {
        if ($compress) {
            $gz = gzopen($output, 'wb6');
            if ($gz === false) throw new RuntimeException("Cannot open gzip stream for [{$output}].");
            $writer = fn (string $d) => gzwrite($gz, $d);
            $closer = fn () => gzclose($gz);
        } else {
            $h = fopen($output, 'wb');
            if ($h === false) throw new RuntimeException("Cannot open output file [{$output}] for writing.");
            $writer = fn (string $d) => fwrite($h, $d);
            $closer = fn () => fclose($h);
        }

        try {
            $body($writer);
            $closer();
        } catch (\Throwable $e) {
            $closer();
            @unlink($output);
            throw $e;
        }
    }

    protected function buildFilename(string $connection, string $database): string
    {
        $now = now();
        $format = (string) config('backup-station.filename_format', 'backup_{database}_{datetime}');

        $name = strtr($format, [
            '{database}' => $this->slug($database),
            '{connection}' => $this->slug($connection),
            '{date}' => $now->format('Y-m-d'),
            '{time}' => $now->format('His'),
            '{datetime}' => $now->format('Y-m-d_His'),
            '{timestamp}' => (string) $now->timestamp,
        ]);

        return $this->slug($name);
    }

    protected function slug(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $value) ?: 'backup';
    }

    protected function shouldFlagAsMonthlyKeep(): bool
    {
        $rule = config('backup-station.retention.monthly_keep', []);

        if (empty($rule['enabled'])) {
            return false;
        }

        $rawDays = $rule['day'] ?? 1;
        $days = array_values(array_filter(
            array_map('intval', (array) $rawDays),
            fn ($d) => $d >= 1 && $d <= 31
        ));

        if (empty($days) || !in_array(now()->day, $days, true)) {
            return false;
        }

        $todayKey = now()->format('Y-m-d');

        foreach ($this->loadMetadata() as $entry) {
            if (($entry['status'] ?? null) !== 'success') {
                continue;
            }
            $created = Carbon::parse($entry['created_at']);
            if ($created->format('Y-m-d') === $todayKey && !empty($entry['monthly_keep'])) {
                return false;
            }
        }

        return true;
    }

    /* -------------------------------------------------------------------- */
    /* Metadata (JSON)                                                       */
    /* -------------------------------------------------------------------- */

    /** Per-request cache to avoid repeated disk reads of backups.json. */
    protected ?array $metadataCache = null;

    public function loadMetadata(): array
    {
        if ($this->metadataCache !== null) {
            return $this->metadataCache;
        }

        $disk = $this->disk();
        $path = $this->metadataPath();

        if (!$disk->exists($path)) {
            return $this->metadataCache = [];
        }

        $raw = $disk->get($path);
        $data = json_decode($raw ?: '[]', true);
        return $this->metadataCache = (is_array($data) ? $data : []);
    }

    public function saveMetadata(array $entries): void
    {
        $entries = array_values($entries);

        // Enforce the JSON-size cap by pruning oldest entries (and their
        // backup files) until the encoded payload fits.
        $entries = $this->enforceMetadataSizeCap($entries);

        $this->disk()->put(
            $this->metadataPath(),
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $this->metadataCache = $entries;
    }

    protected function enforceMetadataSizeCap(array $entries): array
    {
        $capKb = (int) config('backup-station.retention.metadata_max_size_kb', 0);
        if ($capKb <= 0) return $entries;

        $capBytes = $capKb * 1024;

        $encode = fn ($e) => json_encode(array_values($e), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (strlen($encode($entries)) <= $capBytes) {
            return $entries;
        }

        // Sort newest first so we drop oldest. Pinned + monthly_keep are
        // protected last (only dropped if nothing else can be pruned).
        usort($entries, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $protected = [];
        $unprotected = [];
        foreach ($entries as $e) {
            if (!empty($e['pinned']) || !empty($e['monthly_keep'])) {
                $protected[] = $e;
            } else {
                $unprotected[] = $e;
            }
        }

        // Drop oldest unprotected first.
        while (!empty($unprotected) && strlen($encode(array_merge($protected, $unprotected))) > $capBytes) {
            $dropped = array_pop($unprotected);
            if (!empty($dropped['filename'])) {
                $this->deleteFile($dropped['filename']);
            }
        }

        $remaining = array_merge($protected, $unprotected);

        // If we're still over (only protected entries left), drop those too.
        while (!empty($remaining) && strlen($encode($remaining)) > $capBytes) {
            $dropped = array_pop($remaining);
            if (!empty($dropped['filename'])) {
                $this->deleteFile($dropped['filename']);
            }
        }

        return $remaining;
    }

    public function appendMetadata(array $entry): void
    {
        $this->withMetadataLock(function () use ($entry) {
            $this->metadataCache = null;
            $all = $this->loadMetadata();
            $all[] = $entry;
            $this->saveMetadata($all);
        });
    }

    /**
     * Run a callback while holding an exclusive lock on backups.json so
     * concurrent backup runs / cleanup commands don't clobber each other.
     */
    /**
     * Run $cb under an exclusive file lock so concurrent processes don't
     * corrupt backups.json. Uses a local lockfile (flock) instead of the
     * Laravel cache, so the package keeps working even when the user's
     * cache/session driver is broken (e.g. database driver pointing at a
     * non-existent DB during a fresh restore).
     */
    protected function withMetadataLock(\Closure $cb): void
    {
        $lockFile = $this->lockFilePath();
        $fp = @fopen($lockFile, 'c');

        if (!is_resource($fp)) {
            // Filesystem failed to give us a handle — run unlocked rather than fail.
            $cb();
            return;
        }

        try {
            // Try to acquire exclusive lock with a 10s soft timeout.
            $deadline = microtime(true) + 10;
            $acquired = false;
            do {
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }
                usleep(100_000); // 100ms
            } while (microtime(true) < $deadline);

            try {
                $cb();
            } finally {
                if ($acquired) {
                    flock($fp, LOCK_UN);
                }
            }
        } finally {
            fclose($fp);
        }
    }

    protected function lockFilePath(): string
    {
        $dir = sys_get_temp_dir() . '/backup-station';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Disambiguate per-app so two Laravel apps on the same host don't
        // contend on the same lockfile.
        $key = substr(sha1((string) config('app.url') . '|' . base_path()), 0, 12);
        return $dir . '/metadata-' . $key . '.lock';
    }

    public function findById(string $id): ?array
    {
        foreach ($this->loadMetadata() as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }
        return null;
    }

    public function updateEntry(string $id, array $changes): ?array
    {
        $updated = null;
        $this->withMetadataLock(function () use ($id, $changes, &$updated) {
            $this->metadataCache = null;
            $all = $this->loadMetadata();
            foreach ($all as $i => $entry) {
                if (($entry['id'] ?? null) === $id) {
                    $all[$i] = array_merge($entry, $changes);
                    $updated = $all[$i];
                    break;
                }
            }
            if ($updated) {
                $this->saveMetadata($all);
            }
        });
        return $updated;
    }

    /* -------------------------------------------------------------------- */
    /* Operations                                                            */
    /* -------------------------------------------------------------------- */

    public function deleteBackup(string $id): bool
    {
        $entry = $this->findById($id);
        if (!$entry) {
            return false;
        }

        if (!empty($entry['filename'])) {
            $this->deleteFile($entry['filename']);
        }

        $remaining = array_values(array_filter($this->loadMetadata(), fn ($e) => ($e['id'] ?? null) !== $id));
        $this->saveMetadata($remaining);

        return true;
    }

    public function rename(string $id, string $newName): array
    {
        $entry = $this->findById($id);
        if (!$entry) {
            throw new RuntimeException('Backup not found.');
        }

        if (($entry['status'] ?? null) !== 'success' || empty($entry['filename'])) {
            throw new RuntimeException('Cannot rename a failed backup.');
        }

        $extension = '';
        if (str_ends_with($entry['filename'], '.sql.gz')) {
            $extension = '.sql.gz';
        } elseif (str_ends_with($entry['filename'], '.sql')) {
            $extension = '.sql';
        }

        $base = $this->slug(pathinfo($newName, PATHINFO_FILENAME));
        if ($base === '') {
            throw new RuntimeException('Invalid filename.');
        }

        $newFilename = $base . $extension;

        if ($newFilename === $entry['filename']) {
            return $entry;
        }

        if ($this->fileExists($newFilename)) {
            throw new RuntimeException('A file with this name already exists.');
        }

        $this->moveFile($entry['filename'], $newFilename);

        return $this->updateEntry($id, [
            'filename' => $newFilename,
            'path' => $this->pathFor($newFilename),
        ]);
    }

    /**
     * Import an existing backup file from an uploaded file or absolute path.
     * Supported extensions: .sql, .sql.gz, .sql.zip, .gz, .zip
     */
    public function import(string $sourcePath, string $originalName, ?string $note = null): array
    {
        $startedAt = microtime(true);

        if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
            throw new RuntimeException('Uploaded file is empty or unreadable.');
        }

        $this->assertWithinQuota((int) filesize($sourcePath));

        $base = $this->slug(pathinfo($originalName, PATHINFO_FILENAME));
        $ext = $this->detectImportExtension($originalName);

        if ($ext === null) {
            throw new RuntimeException('Unsupported file type. Allowed: .sql, .sql.gz, .gz, .zip');
        }

        if ($base === '') {
            $base = 'imported_' . now()->format('Y-m-d_His');
        }

        $filename = $base . $ext;

        // Avoid collision
        $i = 1;
        while ($this->fileExists($filename)) {
            $filename = $base . '_' . $i . $ext;
            $i++;
        }

        $tempPath = $this->newTempPath($filename);
        copy($sourcePath, $tempPath);

        $finalPath = $this->moveIntoStorage($tempPath, $filename);
        $size = $this->size($filename);

        $context = $this->captureRequestContext();

        $entry = [
            'id' => (string) Str::uuid(),
            'connection' => null,
            'database' => null,
            'driver' => null,
            'disk' => $this->diskName(),
            'filename' => $filename,
            'path' => $finalPath,
            'size' => $size,
            'created_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'note' => $note,
            'status' => 'success',
            'error' => null,
            'pinned' => false,
            'monthly_keep' => false,
            'imported' => true,
            'import' => [
                'original_name' => $originalName,
                'started_at' => date('c', (int) $startedAt),
                'finished_at' => now()->toIso8601String(),
                'ip' => $context['ip'],
                'user_agent' => $context['user_agent'],
                'user_id' => $context['user_id'],
                'user_name' => $context['user_name'],
            ],
        ];

        $this->appendMetadata($entry);

        $this->notifier()->send('success', [
            'filename' => $filename,
            'size' => $this->formatBytes((int) $entry['size']),
            'duration' => $this->formatDuration((int) $entry['duration_ms']),
            'user' => $context['user_name'] ?? null,
            'note' => $note ?: 'IMPORT (' . $originalName . ')',
            'time' => now()->toDateTimeString(),
        ]);

        return $entry;
    }

    protected function detectImportExtension(string $name): ?string
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.sql.gz')) return '.sql.gz';
        if (str_ends_with($lower, '.sql.zip')) return '.sql.zip';
        if (str_ends_with($lower, '.sql')) return '.sql';
        if (str_ends_with($lower, '.gz')) return '.gz';
        if (str_ends_with($lower, '.zip')) return '.zip';

        return null;
    }

    /**
     * Restore a stored backup into its source database connection.
     * Streams the file from the configured disk → temp file → SQL client.
     */
    public function restore(string $id): array
    {
        $startedAt = microtime(true);
        $entry = $this->findById($id);
        if (!$entry) {
            throw new RuntimeException('Backup not found.');
        }
        if (($entry['status'] ?? null) !== 'success' || empty($entry['filename'])) {
            throw new RuntimeException('Cannot restore an incomplete backup.');
        }
        if (!$this->fileExists($entry['filename'])) {
            throw new RuntimeException('Backup file is missing on the storage disk.');
        }

        $connection = $entry['connection'] ?? config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        if (!$dbConfig) {
            throw new RuntimeException("Connection [{$connection}] is not configured.");
        }

        $context = $this->captureRequestContext();

        try {
            $this->doRestore($entry, $dbConfig);
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->recordRestoreAttempt($id, [
                'status' => 'failed',
                'started_at' => date('c', (int) $startedAt),
                'finished_at' => now()->toIso8601String(),
                'duration_ms' => $durationMs,
                'connection' => $connection,
                'database' => $dbConfig['database'] ?? null,
                'driver' => $dbConfig['driver'] ?? null,
                'error' => $e->getMessage(),
            ] + $context);

            $this->notifier()->send('failure', [
                'filename' => $entry['filename'],
                'connection' => $connection,
                'database' => $dbConfig['database'] ?? null,
                'driver' => $dbConfig['driver'] ?? null,
                'size' => $this->formatBytes((int) ($entry['size'] ?? 0)),
                'duration' => $this->formatDuration($durationMs),
                'user' => $context['user_name'] ?? null,
                'note' => 'RESTORE',
                'error' => $e->getMessage(),
                'time' => now()->toDateTimeString(),
            ]);

            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->recordRestoreAttempt($id, [
            'status' => 'success',
            'started_at' => date('c', (int) $startedAt),
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => $durationMs,
            'connection' => $connection,
            'database' => $dbConfig['database'] ?? null,
            'driver' => $dbConfig['driver'] ?? null,
            'error' => null,
        ] + $context);

        $this->notifier()->send('success', [
            'filename' => $entry['filename'],
            'connection' => $connection,
            'database' => $dbConfig['database'] ?? null,
            'driver' => $dbConfig['driver'] ?? null,
            'size' => $this->formatBytes((int) ($entry['size'] ?? 0)),
            'duration' => $this->formatDuration($durationMs),
            'user' => $context['user_name'] ?? null,
            'note' => 'RESTORE',
            'time' => now()->toDateTimeString(),
        ]);

        return $this->findById($id) ?? $entry;
    }

    /**
     * Pull the file from storage, decompress if needed, and pipe into the
     * matching SQL client. Extracted so restore() can wrap it for logging.
     */
    protected function doRestore(array $entry, array $dbConfig): void
    {
        $filename = $entry['filename'];

        // Stage 1 — pull file from storage to a local temp file.
        $stagedPath = $this->newTempPath($filename);
        $stream = $this->disk()->readStream($this->pathFor($filename));
        if (!is_resource($stream)) {
            throw new RuntimeException('Cannot read backup from storage.');
        }
        $out = fopen($stagedPath, 'wb');
        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
            if (is_resource($stream)) fclose($stream);
        }

        $tempFiles = [$stagedPath];

        // Stage 2 — if the file is a ZIP (encrypted or plain), extract it.
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.zip')) {
            $password = (string) config('backup-station.encryption.password', '');
            $extracted = $this->newTempPath('ext_' . $filename);
            $this->extractZip($stagedPath, $extracted, $password);
            $stagedPath = $extracted;
            $tempFiles[] = $extracted;
            $lower = preg_replace('/\.zip$/', '', $lower);
        }

        // Stage 3 — gunzip if needed.
        $sqlPath = $stagedPath;
        if (str_ends_with($lower, '.sql.gz') || str_ends_with($lower, '.gz')) {
            $tempSql = $this->newTempPath('restore_' . pathinfo($filename, PATHINFO_FILENAME) . '.sql');
            $this->gunzipTo($stagedPath, $tempSql);
            $sqlPath = $tempSql;
            $tempFiles[] = $tempSql;
        }

        try {
            $driver = $dbConfig['driver'] ?? 'mysql';
            match ($driver) {
                'mysql', 'mariadb' => $this->restoreMysql($dbConfig, $sqlPath),
                'pgsql', 'postgres' => $this->restorePostgres($dbConfig, $sqlPath),
                'sqlite' => $this->restoreSqlite($dbConfig, $sqlPath),
                default => throw new RuntimeException("Unsupported driver [{$driver}]."),
            };
        } finally {
            foreach ($tempFiles as $tf) {
                if (file_exists($tf)) @unlink($tf);
            }
        }
    }

    /**
     * Append a single restore attempt to the entry's history and refresh
     * the convenience "last_restore_*" fields. Capped to the most recent
     * 20 attempts to keep backups.json small.
     */
    protected function recordRestoreAttempt(string $id, array $attempt): void
    {
        // 1) Update the source backup entry with the latest restore summary
        //    + append to its bounded history array.
        $this->updateEntry($id, [
            'last_restored_at' => $attempt['finished_at'] ?? null,
            'last_restore_ms' => $attempt['duration_ms'] ?? null,
            'last_restore_status' => $attempt['status'] ?? null,
        ]);

        $source = $this->findById($id);
        if ($source) {
            $history = (array) ($source['restores'] ?? []);
            $history[] = $attempt;
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            $this->updateEntry($id, ['restores' => $history]);
        }

        // 2) Append a NEW top-level entry recording the restore as its own
        //    event row in backups.json — so it shows up in the dashboard
        //    just like a backup entry.
        $this->appendMetadata([
            'id' => (string) Str::uuid(),
            'type' => 'restore',
            'source_id' => $id,
            'source_filename' => $source['filename'] ?? null,
            'connection' => $attempt['connection'] ?? null,
            'database' => $attempt['database'] ?? null,
            'driver' => $attempt['driver'] ?? null,
            'disk' => $this->diskName(),
            'filename' => $source['filename'] ?? null,
            'path' => null,
            'size' => 0,
            'created_at' => $attempt['finished_at'] ?? now()->toIso8601String(),
            'duration_ms' => $attempt['duration_ms'] ?? null,
            'note' => 'Restore of ' . ($source['filename'] ?? '—'),
            'status' => $attempt['status'] ?? null,
            'error' => $attempt['error'] ?? null,
            'pinned' => false,
            'monthly_keep' => false,
            'restored_by' => [
                'ip' => $attempt['ip'] ?? null,
                'user_id' => $attempt['user_id'] ?? null,
                'user_name' => $attempt['user_name'] ?? null,
                'user_agent' => $attempt['user_agent'] ?? null,
            ],
        ]);
    }

    /**
     * Snapshot of the HTTP request that triggered the operation, for the
     * audit trail. Safe to call from CLI — returns nulls in that case.
     */
    protected function captureRequestContext(): array
    {
        try {
            $req = app('request');
            $user = $req->user();
            return [
                'ip' => $req->ip(),
                'user_agent' => substr((string) $req->userAgent(), 0, 250),
                'user_id' => optional($user)->getAuthIdentifier(),
                'user_name' => $this->resolveUserName($user),
            ];
        } catch (Throwable) {
            return ['ip' => null, 'user_agent' => null, 'user_id' => null, 'user_name' => null];
        }
    }

    /**
     * Best-effort resolution of a human name for the authenticated user.
     * Order: name → full_name → first_name+last_name → username → email.
     */
    protected function resolveUserName(mixed $user): ?string
    {
        if (!$user) return null;

        $get = function (string $key) use ($user) {
            try {
                $v = data_get($user, $key);
                return is_string($v) && $v !== '' ? $v : null;
            } catch (Throwable) {
                return null;
            }
        };

        if ($v = $get('name')) return $v;
        if ($v = $get('full_name')) return $v;

        $first = $get('first_name');
        $last = $get('last_name');
        if ($first || $last) {
            return trim(($first ?? '') . ' ' . ($last ?? '')) ?: null;
        }

        if ($v = $get('username')) return $v;
        if ($v = $get('email')) return $v;

        return null;
    }

    public function formatDuration(?int $ms): string
    {
        if (!$ms || $ms < 0) return '—';
        if ($ms < 1000) return $ms . ' ms';
        $s = $ms / 1000;
        if ($s < 60) return rtrim(rtrim(number_format($s, 2), '0'), '.') . ' s';
        $m = floor($s / 60);
        $rem = $s - $m * 60;
        return $m . 'm ' . round($rem) . 's';
    }

    /**
     * Wrap a file in a password-protected ZIP using AES-256.
     * The user can extract it later with any standard archive tool.
     */
    /**
     * Wrap a file in a plain (unencrypted) ZIP. Used when archive='zip'.
     *
     * Uses addFile() + an explicit deflate compression method so the
     * archive is readable by the broadest set of tools — including
     * macOS Archive Utility, which rejects non-standard compression.
     */
    protected function plainZip(string $source, string $zipPath, string $innerName): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for ZIP archives.');
        }

        $zip = new \ZipArchive();
        $rc = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($rc !== true) {
            throw new RuntimeException("Cannot create archive [{$zipPath}] (libzip error {$rc}).");
        }

        if (!$zip->addFile($source, $innerName)) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException('Failed to add file to archive.');
        }

        // Pin the compression method to standard deflate (8). Some libzip
        // builds default to deflate64 or other variants that macOS Archive
        // Utility refuses with "unsupported format".
        if (defined('ZipArchive::CM_DEFLATE')) {
            @$zip->setCompressionName($innerName, \ZipArchive::CM_DEFLATE);
        }

        if (!$zip->close()) {
            @unlink($zipPath);
            throw new RuntimeException('Failed to write archive.');
        }

        $this->verifyZip($zipPath);
    }

    /**
     * Sanity-check a freshly written ZIP. Catches corruption / malformed
     * entries early so we never upload a broken archive to S3.
     */
    protected function verifyZip(string $zipPath): void
    {
        $check = new \ZipArchive();
        $rc = $check->open($zipPath, \ZipArchive::CHECKCONS);
        if ($rc !== true) {
            @unlink($zipPath);
            throw new RuntimeException("Generated ZIP failed integrity check (libzip error {$rc}).");
        }
        $check->close();
    }

    protected function encryptZip(string $source, string $zipPath, string $innerName, string $password): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for encryption.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create encrypted archive [{$zipPath}].");
        }

        if (!$zip->setPassword($password)) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException('Failed to set archive password.');
        }

        if (!$zip->addFile($source, $innerName)) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException('Failed to add file to encrypted archive.');
        }

        if (defined('ZipArchive::CM_DEFLATE')) {
            @$zip->setCompressionName($innerName, \ZipArchive::CM_DEFLATE);
        }

        // AES-256 — strong encryption (requires libzip 1.2.0+).
        if (!@$zip->setEncryptionName($innerName, \ZipArchive::EM_AES_256, $password)) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException(
                'AES-256 ZIP encryption is not supported by this PHP build. '
                . 'Upgrade libzip to 1.2.0+ or disable encryption.'
            );
        }

        if (!$zip->close()) {
            @unlink($zipPath);
            throw new RuntimeException('Failed to write encrypted archive.');
        }
    }

    /**
     * Extract the first member of a password-protected ZIP into $outPath.
     */
    /**
     * Extract the first member of a ZIP archive into $outPath.
     * Pass an empty password for plain (unencrypted) archives.
     */
    protected function extractZip(string $zipPath, string $outPath, string $password = ''): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read this backup.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open archive.');
        }
        if ($password !== '') {
            $zip->setPassword($password);
        }

        if ($zip->numFiles < 1) {
            $zip->close();
            throw new RuntimeException('Archive is empty.');
        }

        $innerName = $zip->getNameIndex(0);
        $stream = $zip->getStream($innerName);
        if (!$stream) {
            $zip->close();
            throw new RuntimeException(
                $password !== ''
                    ? 'Wrong password or corrupted archive.'
                    : 'Archive entry is encrypted — set BACKUP_STATION_ENCRYPT_PASSWORD to extract.'
            );
        }

        $out = fopen($outPath, 'wb');
        try {
            while (!feof($stream)) {
                fwrite($out, fread($stream, 1024 * 1024));
            }
        } finally {
            fclose($stream);
            if (is_resource($out)) fclose($out);
            $zip->close();
        }
    }

    /** @deprecated alias kept for backward compatibility */
    protected function decryptZip(string $zipPath, string $outPath, string $password): void
    {
        $this->extractZip($zipPath, $outPath, $password);
    }

    protected function gunzipTo(string $gzPath, string $sqlPath): void
    {
        $in = gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open gzip stream for decompression.');
        }
        $out = fopen($sqlPath, 'wb');
        try {
            while (!gzeof($in)) {
                fwrite($out, gzread($in, 1024 * 1024));
            }
        } finally {
            gzclose($in);
            if (is_resource($out)) fclose($out);
        }
    }

    protected function restoreMysql(array $config, string $sqlFile): void
    {
        $bin = $this->resolveBinary('mysql');
        $defaultsFile = $this->writeMysqlDefaultsFile($config);

        try {
            if (config('backup-station.restore_auto_create_database', true)) {
                $this->ensureMysqlDatabaseExists($bin, $defaultsFile, $config);
            }

            $cmd = [
                $bin,
                '--defaults-extra-file=' . $defaultsFile,
                '-h', $config['host'] ?? '127.0.0.1',
                '-P', (string) ($config['port'] ?? 3306),
                '--default-character-set=utf8mb4',
                $config['database'],
            ];

            $this->runRestoreCommand($cmd, $sqlFile);
        } finally {
            @unlink($defaultsFile);
        }
    }

    protected function ensureMysqlDatabaseExists(string $bin, string $defaultsFile, array $config): void
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '') return;

        $charset = $config['charset'] ?? 'utf8mb4';
        $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

        // Backtick-escape DB name for the CREATE statement.
        $safe = '`' . str_replace('`', '``', $database) . '`';
        $sql = sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s;', $safe, $charset, $collation);

        $cmd = [
            $bin,
            '--defaults-extra-file=' . $defaultsFile,
            '-h', $config['host'] ?? '127.0.0.1',
            '-P', (string) ($config['port'] ?? 3306),
            '-e', $sql,
        ];

        $process = new Process($cmd);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to create database: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    protected function restorePostgres(array $config, string $sqlFile): void
    {
        $bin = $this->resolveBinary('psql');
        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];

        if (config('backup-station.restore_auto_create_database', true)) {
            $this->ensurePostgresDatabaseExists($bin, $config, $env);
        }

        $cmd = [
            $bin,
            '-h', $config['host'] ?? '127.0.0.1',
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? 'postgres'),
            '-d', $config['database'],
            '-v', 'ON_ERROR_STOP=1',
        ];

        $this->runRestoreCommand($cmd, $sqlFile, $env);
    }

    protected function ensurePostgresDatabaseExists(string $bin, array $config, array $env): void
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '') return;

        // Check if it already exists.
        $checkCmd = [
            $bin,
            '-h', $config['host'] ?? '127.0.0.1',
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? 'postgres'),
            '-d', 'postgres',
            '-tAc', "SELECT 1 FROM pg_database WHERE datname = '" . str_replace("'", "''", $database) . "'",
        ];
        $check = new Process($checkCmd);
        $check->setEnv(array_replace($_SERVER ?: [], $env));
        $check->setTimeout(60);
        $check->run();

        if (trim($check->getOutput()) === '1') {
            return;
        }

        // Create it.
        $safe = '"' . str_replace('"', '""', $database) . '"';
        $createCmd = [
            $bin,
            '-h', $config['host'] ?? '127.0.0.1',
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? 'postgres'),
            '-d', 'postgres',
            '-c', "CREATE DATABASE {$safe}",
        ];
        $create = new Process($createCmd);
        $create->setEnv(array_replace($_SERVER ?: [], $env));
        $create->setTimeout(60);
        $create->run();

        if (!$create->isSuccessful()) {
            throw new RuntimeException('Failed to create database: ' . trim($create->getErrorOutput() ?: $create->getOutput()));
        }
    }

    protected function restoreSqlite(array $config, string $sqlFile): void
    {
        $bin = $this->resolveBinary('sqlite3');
        $dbPath = $config['database'];

        // Auto-create: ensure the parent dir exists; sqlite3 creates the file on first write.
        if (config('backup-station.restore_auto_create_database', true)) {
            $dir = dirname($dbPath);
            if ($dir && !is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $cmd = [$bin, $dbPath];
        $this->runRestoreCommand($cmd, $sqlFile);
    }

    protected function runRestoreCommand(array $cmd, string $sqlFile, array $env = []): void
    {
        $timeout = (int) config('backup-station.timeout', 1800);
        $process = new Process($cmd);
        $process->setTimeout($timeout);
        $process->setInput(fopen($sqlFile, 'rb'));

        if ($env) {
            $process->setEnv(array_replace($_SERVER ?: [], $env));
        }

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Restore command failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    public function togglePin(string $id): ?array
    {
        $entry = $this->findById($id);
        if (!$entry) {
            return null;
        }
        return $this->updateEntry($id, ['pinned' => !($entry['pinned'] ?? false)]);
    }

    /* -------------------------------------------------------------------- */
    /* Retention                                                             */
    /* -------------------------------------------------------------------- */

    /**
     * Predict which existing backups the retention policy will delete
     * within the next $days days, based on today's max_backups, age limit,
     * and monthly cap. Read-only — does not mutate metadata.
     *
     * @return array{
     *     entries: array<int,array<string,mixed>>,
     *     reasons: array<string,string>,
     *     by_day: array<string,int>,
     * }
     */
    public function forecastRetention(int $days = 7): array
    {
        $entries = $this->loadMetadata();
        usort($entries, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $maxBackups = (int) (config('backup-station.retention.max_backups') ?? 0);
        $keepDays = (int) (config('backup-station.retention.keep_for_days') ?? 0);
        $monthlyRule = config('backup-station.retention.monthly_keep', []);
        $monthlyEnabled = !empty($monthlyRule['enabled']);
        $monthlyKeepCount = (int) ($monthlyRule['keep_months'] ?? 0);

        $window = now()->copy()->addDays($days);
        $doomed = [];   // id => entry
        $reasons = [];  // id => reason
        $byDay = [];    // YYYY-MM-DD => count

        $markDoomed = function (array $entry, string $reason, ?\Carbon\Carbon $deletionDate = null) use (&$doomed, &$reasons, &$byDay) {
            $id = $entry['id'] ?? null;
            if (!$id || isset($doomed[$id])) return;
            $doomed[$id] = $entry;
            $reasons[$id] = $reason;
            $key = ($deletionDate ?? now())->toDateString();
            $byDay[$key] = ($byDay[$key] ?? 0) + 1;
        };

        // 1) Monthly cap — extras beyond keep_months get pruned at next run.
        if ($monthlyEnabled && $monthlyKeepCount > 0) {
            $monthly = array_values(array_filter($entries, fn ($e) => !empty($e['monthly_keep']) && ($e['status'] ?? null) === 'success'));
            foreach (array_slice($monthly, $monthlyKeepCount) as $e) {
                if (!empty($e['pinned'])) continue;
                $markDoomed($e, 'monthly cap exceeded');
            }
        }

        // 2) Age-based pruning — entries that will cross the cutoff inside the window.
        if ($keepDays > 0) {
            foreach ($entries as $e) {
                if (!empty($e['pinned'])) continue;
                if ($monthlyEnabled && !empty($e['monthly_keep'])) continue;
                if (!empty($e['created_at'])) {
                    $expiresAt = \Carbon\Carbon::parse($e['created_at'])->addDays($keepDays);
                    if ($expiresAt->lessThanOrEqualTo($window)) {
                        $markDoomed($e, 'age limit (' . $keepDays . 'd) reached on ' . $expiresAt->toDateString(), $expiresAt);
                    }
                }
            }
        }

        // 3) Hard count cap — oldest unprotected entries beyond max_backups.
        if ($maxBackups > 0 && count($entries) > $maxBackups) {
            $protected = $unprotected = [];
            foreach ($entries as $e) {
                if (!empty($e['pinned']) || ($monthlyEnabled && !empty($e['monthly_keep']))) {
                    $protected[] = $e;
                } else {
                    $unprotected[] = $e;
                }
            }
            $allowed = max(0, $maxBackups - count($protected));
            foreach (array_slice($unprotected, $allowed) as $e) {
                $markDoomed($e, 'max copies (' . $maxBackups . ') exceeded');
            }
        }

        // 4) Future monthly_keep candidates that will land outside the keep_months
        //    window — only relevant when adding a new monthly snapshot tomorrow,
        //    so we skip predicting that here for simplicity.

        ksort($byDay);

        return [
            'entries' => array_values($doomed),
            'reasons' => $reasons,
            'by_day' => $byDay,
        ];
    }

    public function applyRetentionPolicy(): array
    {
        $deleted = [];
        $entries = $this->loadMetadata();

        usort($entries, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $maxBackups = (int) (config('backup-station.retention.max_backups') ?? 0);
        $keepDays = (int) (config('backup-station.retention.keep_for_days') ?? 0);
        $monthlyRule = config('backup-station.retention.monthly_keep', []);
        $monthlyEnabled = !empty($monthlyRule['enabled']);
        $monthlyKeepCount = (int) ($monthlyRule['keep_months'] ?? 0);

        if ($monthlyEnabled && $monthlyKeepCount > 0) {
            $monthlyEntries = array_values(array_filter(
                $entries,
                fn ($e) => !empty($e['monthly_keep']) && ($e['status'] ?? null) === 'success'
            ));

            if (count($monthlyEntries) > $monthlyKeepCount) {
                $surplus = array_slice($monthlyEntries, $monthlyKeepCount);
                foreach ($surplus as $entry) {
                    if (!empty($entry['pinned'])) {
                        continue;
                    }
                    $this->deleteBackup($entry['id']);
                    $deleted[] = $entry['id'];
                }
                $entries = $this->loadMetadata();
                usort($entries, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            }
        }

        if ($keepDays > 0) {
            $cutoff = now()->subDays($keepDays);
            foreach ($entries as $entry) {
                if (!empty($entry['pinned'])) {
                    continue;
                }
                if ($monthlyEnabled && !empty($entry['monthly_keep'])) {
                    continue;
                }
                $created = Carbon::parse($entry['created_at'] ?? null);
                if ($created->lt($cutoff)) {
                    $this->deleteBackup($entry['id']);
                    $deleted[] = $entry['id'];
                }
            }
            $entries = $this->loadMetadata();
            usort($entries, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        }

        if ($maxBackups > 0 && count($entries) > $maxBackups) {
            $protected = [];
            $unprotected = [];

            foreach ($entries as $entry) {
                if (!empty($entry['pinned']) || ($monthlyEnabled && !empty($entry['monthly_keep']))) {
                    $protected[] = $entry;
                } else {
                    $unprotected[] = $entry;
                }
            }

            $allowedUnprotected = max(0, $maxBackups - count($protected));
            $surplus = array_slice($unprotected, $allowedUnprotected);

            foreach ($surplus as $entry) {
                $this->deleteBackup($entry['id']);
                $deleted[] = $entry['id'];
            }
        }

        return $deleted;
    }

    /* -------------------------------------------------------------------- */
    /* Helpers                                                               */
    /* -------------------------------------------------------------------- */

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    public function totalSize(): int
    {
        return array_sum(array_map(fn ($e) => (int) ($e['size'] ?? 0), $this->loadMetadata()));
    }

    public function stats(): array
    {
        $all = $this->loadMetadata();
        $success = array_filter($all, fn ($e) => ($e['status'] ?? null) === 'success');
        $failed = array_filter($all, fn ($e) => ($e['status'] ?? null) === 'failed');
        $monthly = array_filter($all, fn ($e) => !empty($e['monthly_keep']));
        $pinned = array_filter($all, fn ($e) => !empty($e['pinned']));

        $latest = null;
        foreach ($success as $entry) {
            if ($latest === null || strcmp($entry['created_at'] ?? '', $latest['created_at'] ?? '') > 0) {
                $latest = $entry;
            }
        }

        return [
            'total' => count($all),
            'success' => count($success),
            'failed' => count($failed),
            'monthly' => count($monthly),
            'pinned' => count($pinned),
            'total_size' => $this->totalSize(),
            'latest' => $latest,
        ];
    }

}
