<?php

namespace MahmoudMhamed\BackupStation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MahmoudMhamed\BackupStation\BackupStationService;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public ?string $connection = null,
        public ?string $note = null,
        public array $overrides = [],
    ) {
        // Per-job queue/connection routing from config — falls back to the
        // application's default queue connection / queue when not set.
        $conn = config('backup-station.queue.connection') ?: config('queue.default');
        $queue = config('backup-station.queue.queue')
            ?: config("queue.connections.{$conn}.queue", 'default');

        $this->onConnection($conn);
        $this->onQueue($queue);
    }

    public function timeout(): int
    {
        return (int) config('backup-station.timeout', 1800);
    }

    public function handle(BackupStationService $service): void
    {
        $service->runBackup($this->connection, $this->note, $this->overrides);
    }
}
