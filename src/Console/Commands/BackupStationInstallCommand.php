<?php

namespace MahmoudMhamed\BackupStation\Console\Commands;

use Illuminate\Console\Command;

class BackupStationInstallCommand extends Command
{
    protected $signature = 'backup-station:install {--force : Overwrite existing files}';

    protected $description = 'Publish the Backup Station config and views.';

    public function handle(): int
    {
        $this->info('Publishing Backup Station config…');
        $this->call('vendor:publish', [
            '--tag' => 'backup-station-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->newLine();
        $this->info('✓ Backup Station installed.');
        $this->line('  • Config:  config/backup-station.php');
        $this->line('  • Routes:  /' . config('backup-station.viewer.route_prefix', 'backup-station'));
        $this->line('  • Run a backup now:  php artisan backup-station:run');

        return self::SUCCESS;
    }
}
