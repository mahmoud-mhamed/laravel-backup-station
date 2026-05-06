<?php

namespace MahmoudMhamed\BackupStation\Console\Commands;

use Illuminate\Console\Command;
use MahmoudMhamed\BackupStation\Notifications\BackupNotifier;

class BackupStationTestNotificationCommand extends Command
{
    protected $signature = 'backup-station:test-notification
        {channel? : log|mail|slack|telegram|discord — defaults to all enabled channels}
        {--event=success : success or failure}';

    protected $description = 'Send a test notification through one or all configured channels.';

    public function handle(BackupNotifier $notifier): int
    {
        $event = (string) $this->option('event');
        if (!in_array($event, ['success', 'failure'], true)) {
            $this->error('Event must be "success" or "failure".');
            return self::FAILURE;
        }

        $channel = $this->argument('channel');

        $context = [
            'filename' => 'test_backup.sql.gz',
            'connection' => config('database.default'),
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'driver' => config('database.connections.' . config('database.default') . '.driver'),
            'size' => '1.23 MB',
            'note' => 'Test notification',
            'time' => now()->toDateTimeString(),
            'error' => $event === 'failure' ? 'This is a fake error for testing.' : null,
        ];

        if ($channel) {
            $cfg = config("backup-station.notifications.on_{$event}");
            config([
                "backup-station.notifications.on_{$event}.enabled" => true,
                "backup-station.notifications.on_{$event}.channels" => [$channel],
            ]);
            $this->info("Sending test {$event} via [{$channel}]…");
            $notifier->send($event, $context);
            config(["backup-station.notifications.on_{$event}" => $cfg]);
        } else {
            $this->info("Sending test {$event} via all enabled channels…");
            $notifier->send($event, $context);
        }

        $this->info('✓ Sent. Check the destination(s).');
        return self::SUCCESS;
    }
}
