<?php

namespace MahmoudMhamed\BackupStation\Console\Commands;

use Illuminate\Console\Command;
use MahmoudMhamed\BackupStation\BackupStationService;

class BackupStationCleanupCommand extends Command
{
    protected $signature = 'backup-station:cleanup';

    protected $description = 'Apply the retention policy and prune old backups.';

    public function handle(BackupStationService $service): int
    {
        $deleted = $service->applyRetentionPolicy();
        $this->info(count($deleted) . ' backup(s) pruned.');
        return self::SUCCESS;
    }
}
