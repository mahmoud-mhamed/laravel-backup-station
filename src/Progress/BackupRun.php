<?php

namespace MahmoudMhamed\BackupStation\Progress;

use MahmoudMhamed\BackupStation\BackupStationService;
use Throwable;

/**
 * Progress of one backup run, saved as runs/<id>.json on the backup disk so
 * the dashboard can read it from any process: the request that started the
 * run, a queue worker on another server, or the scheduler.
 *
 * Writing progress must never break a backup, so every save swallows its
 * own errors.
 */
class BackupRun
{
    public const ACTIVE = ['queued', 'running'];

    /** Run ids look like 20261116-214012-ab12cd34 (UTC start time + random). */
    public const ID_PATTERN = '/^\d{8}-\d{6}-[a-z0-9]{8}$/';

    protected float $lastSave = 0.0;

    public function __construct(protected BackupStationService $service, public array $data)
    {
    }

    public static function enabled(): bool
    {
        return (bool) config('backup-station.progress.enabled', true);
    }

    public static function create(BackupStationService $service, string $trigger, ?string $note): self
    {
        $now = now()->utc();
        $run = new self($service, [
            'id' => $now->format('Ymd-His') . '-' . strtolower(bin2hex(random_bytes(4))),
            'trigger' => $trigger,
            'note' => $note,
            'status' => 'queued',
            'stage' => 'queued',
            'created_at' => $now->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now->toIso8601String(),
            'total' => null,
            'current' => null,
            'connections' => [],
            'error' => null,
            'dismissed' => false,
        ]);
        $run->save(true);
        self::prune($service);

        return $run;
    }

    public static function find(BackupStationService $service, string $id): ?self
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        try {
            $path = self::path($service, $id);
            if (!$service->disk()->exists($path)) {
                return null;
            }
            $data = json_decode((string) $service->disk()->get($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? new self($service, $data) : null;
    }

    /**
     * Raw run records started within the last $hours, newest first. Ids
     * start with the UTC start time, so old files are skipped by name
     * without being read.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(BackupStationService $service, int $hours = 24): array
    {
        $cutoff = now()->utc()->subHours($hours)->format('Ymd-His');
        $runs = [];

        foreach (self::ids($service) as $id) {
            if (strcmp($id, $cutoff) < 0) {
                break;
            }
            if ($run = self::find($service, $id)) {
                $runs[] = $run->data;
            }
        }

        return $runs;
    }

    /** @return string[] run ids, newest first */
    protected static function ids(BackupStationService $service): array
    {
        try {
            $files = $service->disk()->files($service->pathFor('runs'));
        } catch (Throwable) {
            return [];
        }

        $ids = [];
        foreach ($files as $file) {
            $id = basename($file, '.json');
            if (str_ends_with($file, '.json') && preg_match(self::ID_PATTERN, $id)) {
                $ids[] = $id;
            }
        }
        rsort($ids);

        return $ids;
    }

    /** Delete run records older than a week. */
    protected static function prune(BackupStationService $service): void
    {
        $cutoff = now()->utc()->subDays(7)->format('Ymd-His');
        foreach (self::ids($service) as $id) {
            if (strcmp($id, $cutoff) < 0) {
                try {
                    $service->disk()->delete(self::path($service, $id));
                } catch (Throwable) {
                    // Leave it for the next prune.
                }
            }
        }
    }

    protected static function path(BackupStationService $service, string $id): string
    {
        return $service->pathFor('runs/' . $id . '.json');
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function isActive(): bool
    {
        return in_array($this->data['status'] ?? null, self::ACTIVE, true);
    }

    /**
     * @param bool $force false = save only when the progress interval has
     *                    passed since the last save (used per dump chunk).
     */
    public function save(bool $force = true): void
    {
        $now = microtime(true);
        $interval = max(1, (int) config('backup-station.progress.interval', 2));
        if (!$force && ($now - $this->lastSave) < $interval) {
            return;
        }

        $this->lastSave = $now;
        $this->data['updated_at'] = now()->utc()->toIso8601String();

        try {
            $this->service->disk()->put(
                self::path($this->service, $this->id()),
                json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            );
        } catch (Throwable) {
            // Progress is best effort; the backup itself must go on.
        }
    }

    /**
     * @param string[] $connections
     * @param array<string,string> $labels
     */
    public function begin(array $connections, array $labels): void
    {
        $this->data['status'] = 'running';
        $this->data['stage'] = 'backing_up';
        $this->data['started_at'] = now()->utc()->toIso8601String();
        $this->data['total'] = count($connections);
        $this->data['connections'] = array_map(fn ($c) => [
            'name' => $c,
            'label' => $labels[$c] ?? $c,
            'status' => 'pending',
        ], array_values($connections));
        $this->save();
    }

    public function connectionStarted(int $index, ?string $driver, ?int $estimate, ?int $tablesTotal): void
    {
        $this->data['current'] = $index;
        $this->data['connections'][$index] = array_merge($this->data['connections'][$index] ?? [], [
            'status' => 'running',
            'stage' => 'preparing',
            'driver' => $driver,
            'started_at' => now()->utc()->toIso8601String(),
            'bytes' => 0,
            'estimate' => $estimate,
            'table' => null,
            'tables_done' => 0,
            'tables_total' => $tablesTotal,
            'stage_total' => null,
            'stage_fraction' => null,
        ]);
        $this->save();
    }

    public function currentDriver(): ?string
    {
        $i = $this->data['current'];
        return $i === null ? null : ($this->data['connections'][$i]['driver'] ?? null);
    }

    /** dumping | compressing | encrypting | saving */
    public function stage(string $stage, ?int $total = null): void
    {
        $i = $this->data['current'];
        if ($i === null) return;

        $this->data['connections'][$i]['stage'] = $stage;
        $this->data['connections'][$i]['stage_total'] = $total;
        $this->data['connections'][$i]['stage_fraction'] = null;
        $this->save();
    }

    /** One dump chunk: $bytes uncompressed bytes, $tables data sections that began in it. */
    public function dumped(int $bytes, array $tables = []): void
    {
        $i = $this->data['current'];
        if ($i === null) return;

        $c = &$this->data['connections'][$i];
        $c['bytes'] = (int) ($c['bytes'] ?? 0) + $bytes;
        if ($tables) {
            $c['table'] = end($tables);
            $c['tables_done'] = (int) ($c['tables_done'] ?? 0) + count($tables);
        }
        unset($c);

        $this->save(false);
    }

    /** Compression progress (0..1) where the archiver reports it. */
    public function stageFraction(float $fraction): void
    {
        $i = $this->data['current'];
        if ($i === null) return;

        $this->data['connections'][$i]['stage_fraction'] = max(0.0, min(1.0, $fraction));
        $this->save(false);
    }

    /** Keep updated_at fresh during steps that report nothing else. */
    public function heartbeat(): void
    {
        $this->save(false);
    }

    public function connectionFinished(int $index, string $status, array $extra = []): void
    {
        $this->data['connections'][$index] = array_merge($this->data['connections'][$index] ?? [], [
            'status' => $status,
            'stage' => 'done',
            'finished_at' => now()->utc()->toIso8601String(),
        ], $extra);
        if ($this->data['current'] === $index) {
            $this->data['current'] = null;
        }
        $this->save();
    }

    public function runStage(string $stage): void
    {
        $this->data['stage'] = $stage;
        $this->save();
    }

    /**
     * End the run. Connections never reached (e.g. after a quota refusal on
     * a multi-connection run) become "skipped". $error is a failure outside
     * any single connection, or the one that stopped the run.
     */
    public function finish(?string $error = null): void
    {
        $success = $failed = 0;
        foreach ($this->data['connections'] as $i => $c) {
            $status = $c['status'] ?? 'pending';
            if ($status === 'pending') {
                $this->data['connections'][$i]['status'] = 'skipped';
            } elseif ($status === 'running') {
                $this->data['connections'][$i]['status'] = 'failed';
                $this->data['connections'][$i]['error'] = $error ?? 'Stopped before it finished.';
                $this->data['connections'][$i]['finished_at'] = now()->utc()->toIso8601String();
                $failed++;
            } elseif ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
            }
        }

        if ($error !== null && $failed === 0) {
            $this->data['error'] = $error;
        }

        $this->data['status'] = ($failed === 0 && $error === null)
            ? 'success'
            : ($success > 0 ? 'partial' : 'failed');
        $this->data['stage'] = 'done';
        $this->data['current'] = null;
        $this->data['finished_at'] = now()->utc()->toIso8601String();
        $this->save();
    }

    public function dismiss(): void
    {
        $this->data['dismissed'] = true;
        $this->save();
    }
}
