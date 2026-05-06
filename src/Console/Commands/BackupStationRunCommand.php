<?php

namespace MahmoudMhamed\BackupStation\Console\Commands;

use Illuminate\Console\Command;
use MahmoudMhamed\BackupStation\BackupStationService;
use Throwable;

class BackupStationRunCommand extends Command
{
    protected $signature = 'backup-station:run
        {--schedule= : Run a named schedule from config/backup-station.php}
        {--connection= : Specific database connection to back up}
        {--note= : Note attached to the backup}
        {--tables= : Comma-separated tables to include (overrides config)}
        {--mode=full : full | structure | data}';

    protected $description = 'Run a database backup now and apply retention policies.';

    public function handle(BackupStationService $service): int
    {
        $connection = $this->option('connection');
        $note = $this->option('note');
        $tables = $this->parseList($this->option('tables'));
        $exclude = [];
        $mode = $this->option('mode') ?: 'full';

        // If --schedule is given, pull all defaults from that named schedule.
        if ($scheduleName = $this->option('schedule')) {
            $cfg = $this->resolveSchedule($scheduleName);
            if (!$cfg) {
                $this->error("Schedule [{$scheduleName}] not found in config.");
                return self::FAILURE;
            }
            $connection = $connection ?: ($cfg['connection'] ?? null);
            $note = $note ?: ($cfg['note'] ?? null);
            $tables = $tables ?: (array) ($cfg['tables']['include'] ?? []);
            $exclude = (array) ($cfg['tables']['exclude'] ?? []);
            $mode = $mode === 'full' ? ($cfg['mode'] ?? 'full') : $mode;
            $this->info("Running schedule [{$scheduleName}]…");
        } else {
            $this->info('Running backup…');
        }

        $overrides = [
            'tables' => $tables,
            'exclude' => $exclude,
            'mode' => in_array($mode, ['full', 'structure', 'data'], true) ? $mode : 'full',
        ];

        try {
            $created = $service->runBackup($connection, $note, $overrides);
        } catch (Throwable $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        foreach ($created as $entry) {
            $this->line(sprintf(
                '  ✓ %s  (%s)',
                $entry['filename'],
                $service->formatBytes((int) $entry['size'])
            ));
        }

        $this->info('Done. ' . count($created) . ' backup(s) created.');
        return self::SUCCESS;
    }

    protected function parseList(?string $raw): array
    {
        if (!$raw) return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    protected function resolveSchedule(string $name): ?array
    {
        foreach ((array) config('backup-station.schedules', []) as $cfg) {
            if (($cfg['name'] ?? null) === $name) {
                return $cfg;
            }
        }
        return null;
    }
}
