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

    /**
     * @param string|null $targetConnection Database connection to back up (null = every connection in scope).
     *                                      Not named `$connection`: that property belongs to Queueable
     *                                      (the queue connection) and redeclaring it is a fatal error.
     */
    public function __construct(
        public ?string $targetConnection = null,
        public ?string $note = null,
        public array $overrides = [],
        public bool $scheduled = false,
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
        // When run after the HTTP response (no queue worker), keep going even
        // though the client is gone and PHP's max_execution_time would hit.
        ignore_user_abort(true);
        @set_time_limit(0);

        $service->runBackup($this->targetConnection, $this->note, $this->overrides, $this->scheduled);
    }
}
