<?php

namespace MahmoudMhamed\BackupStation;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use MahmoudMhamed\BackupStation\Console\Commands\BackupStationCleanupCommand;
use MahmoudMhamed\BackupStation\Console\Commands\BackupStationInstallCommand;
use MahmoudMhamed\BackupStation\Console\Commands\BackupStationRunCommand;
use MahmoudMhamed\BackupStation\Console\Commands\BackupStationTestNotificationCommand;
use MahmoudMhamed\BackupStation\Http\Middleware\AuthorizeBackupStation;
use MahmoudMhamed\BackupStation\Http\Middleware\BackupStationThrottle;
use MahmoudMhamed\BackupStation\Notifications\BackupNotifier;

class BackupStationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/backup-station.php', 'backup-station');

        $this->app->singleton(BackupStationService::class, fn () => new BackupStationService());
        $this->app->singleton(BackupNotifier::class, fn () => new BackupNotifier());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/backup-station.php' => config_path('backup-station.php'),
        ], 'backup-station-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/backup-station'),
        ], 'backup-station-views');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'backup-station');

        if ($this->isEnabledForEnvironment() && config('backup-station.viewer.enabled', true)) {
            $this->registerRouteMiddleware();

            // Hosts with custom routing needs (e.g. multi-tenant apps that
            // register the dashboard per domain group) set register_routes
            // to false and load routes/web.php themselves.
            if (config('backup-station.viewer.register_routes', true)) {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupStationInstallCommand::class,
                BackupStationRunCommand::class,
                BackupStationCleanupCommand::class,
                BackupStationTestNotificationCommand::class,
            ]);
        }

        if ($this->isEnabledForEnvironment()) {
            $this->registerSchedules();
        }
    }

    protected function isEnabledForEnvironment(): bool
    {
        if ($this->app->isLocal()) {
            return (bool) config('backup-station.enable_local', true);
        }
        return (bool) config('backup-station.enable_production', true);
    }

    protected function registerRouteMiddleware(): void
    {
        $router = $this->app['router'];

        $middleware = config('backup-station.viewer.middleware', ['web']);
        $middleware[] = AuthorizeBackupStation::class;

        $router->middlewareGroup('backup-station', $middleware);

        // Custom file-based throttle so the package doesn't depend on the
        // user's cache driver (which may be broken during a fresh restore).
        $router->aliasMiddleware('bs.throttle', BackupStationThrottle::class);
    }

    protected function registerSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            foreach ((array) config('backup-station.schedules', []) as $cfg) {
                if (empty($cfg['enabled'])) continue;

                $name = (string) ($cfg['name'] ?? 'default');
                $event = $schedule->command('backup-station:run --schedule=' . escapeshellarg($name));

                $this->applyScheduleFrequency($event, $cfg);
                $this->applyScheduleDays($event, $cfg['days'] ?? ['*']);

                if (!empty($cfg['without_overlapping'])) $event->withoutOverlapping();
                if (!empty($cfg['on_one_server'])) $event->onOneServer();
                if (!empty($cfg['run_in_background'])) $event->runInBackground();
            }
        });
    }

    protected function applyScheduleFrequency(Event $event, array $cfg): void
    {
        $frequency = $cfg['frequency'] ?? 'daily';
        $time = $cfg['time'] ?? '02:00';
        $dayOfMonth = (int) ($cfg['day_of_month'] ?? 1);

        switch ($frequency) {
            case 'twiceDaily':
                $event->twiceDaily(1, 13);
                break;
            case 'monthly':
                $event->monthlyOn($dayOfMonth, $time);
                break;
            case 'cron':
                $event->cron((string) ($cfg['cron'] ?? '0 2 * * *'));
                break;
            case 'daily':
            default:
                $event->dailyAt($time);
                break;
        }
    }

    protected function applyScheduleDays(Event $event, $days): void
    {
        if (!is_array($days) || $days === [] || in_array('*', $days, true)) {
            return;
        }

        $map = [
            'sunday' => 0, 'sun' => 0,
            'monday' => 1, 'mon' => 1,
            'tuesday' => 2, 'tue' => 2,
            'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'thu' => 4,
            'friday' => 5, 'fri' => 5,
            'saturday' => 6, 'sat' => 6,
        ];

        $resolved = [];
        foreach ($days as $day) {
            if (is_int($day)) {
                $resolved[] = $day;
            } elseif (is_string($day)) {
                $key = strtolower(trim($day));
                if (isset($map[$key])) {
                    $resolved[] = $map[$key];
                } elseif (ctype_digit($key)) {
                    $resolved[] = (int) $key;
                }
            }
        }

        if ($resolved !== []) {
            $event->days(array_values(array_unique($resolved)));
        }
    }
}
