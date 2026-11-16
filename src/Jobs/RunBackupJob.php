<?php

namespace MahmoudMhamed\BackupStation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MahmoudMhamed\BackupStation\BackupStationService;
use MahmoudMhamed\BackupStation\Progress\BackupRun;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Progress run created by the dashboard. A plain property with a
     * default, not a promoted one, so a job queued before this existed
     * still unserializes.
     */
    public ?string $runId = null;

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
        ?string $runId = null,
    ) {
        $this->runId = $runId;

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

        $service->runBackup($this->targetConnection, $this->note, $this->overrides, $this->scheduled, $this->runId);
    }

    /**
     * The queue gave up on the job — e.g. the worker hit the job timeout and
     * was killed mid-backup. Show that as the run's failure reason.
     */
    public function failed(?Throwable $e = null): void
    {
        if (!$this->runId) {
            return;
        }

        $run = BackupRun::find(app(BackupStationService::class), $this->runId);
        if ($run && $run->isActive()) {
            $run->finish('The backup job failed: ' . ($e ? $e->getMessage() : 'unknown error'));
        }
    }
}
