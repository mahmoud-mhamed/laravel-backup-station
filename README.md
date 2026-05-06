# Laravel Backup Station

Automatic database backups for Laravel — schedule, retention rules, monthly snapshots, rename, download, and a beautiful dashboard. Built in the same style as [`laravel-logman`](https://github.com/mahmoud-mhamed/laravel-logman).

## Features

- 📅 **Scheduled backups** — hourly / daily / weekly / monthly / custom cron, no scheduler boilerplate
- 🗂️ **Max copies retention** — hard cap on the number of backup files
- 🗓️ **Monthly keep rule** — keep one backup from a chosen day-of-month for N months
- ⏳ **Age-based pruning** — delete backups older than X days
- 📌 **Mark** important backups so retention never deletes them
- ↺ **Restore** any successful backup back into its database with one click
- ⚠ **Missing-file detection** — entries whose file no longer exists on the disk are flagged in the dashboard
- 🐬 **Multi-driver** — MySQL/MariaDB, PostgreSQL, SQLite
- 🗜️ **Gzip compression** — streamed, no temp files
- ⬇️ **Download** any backup with one click
- ✏️ **Rename** backups in place (extension preserved)
- 🔍 **Search & filter** by filename, database, status
- 🌑 **Dark mode** with persistent preference
- 🔒 **Password protection** + authorize callback for the dashboard
- 📒 **JSON metadata** stored in `backups.json` next to the SQL files
- 🛠️ **Artisan commands** for manual run / cleanup / install
- 🔁 **Multi-connection** support — back up several DB connections in one run
- 📣 **Notifications** — Mail / Slack / Telegram / Discord / Log, with per-event routing
- 🚀 **Async by default** — Mail, Telegram, Discord deliver via the queue (`afterResponse`) so the dashboard never blocks

## Installation

```bash
composer require mahmoud-mhamed/laravel-backup-station
php artisan backup-station:install
```

Open `/backup-station` in your browser.

## Configuration

Edit `config/backup-station.php`:

```php
'schedule' => [
    'enabled' => true,
    'frequency' => 'daily',          // hourly|daily|twiceDaily|monthly|cron
    'time' => '02:00',
    'days' => ['*'],                 // every day; or ['monday','wednesday','friday'], or [1,3,5]
    'day_of_month' => 1,             // used by frequency=monthly
    'cron' => '0 2 * * *',
],

'storage' => [
    'disk' => env('BACKUP_STATION_DISK'),     // null = filesystems.default; or 's3', 'minio', 'spaces'…
    'path' => env('BACKUP_STATION_PATH', 'backup-station'),
],

'notifications' => [
    'on_success' => ['enabled' => true, 'channels' => ['log']],
    'on_failure' => ['enabled' => true, 'channels' => ['log', 'mail']],

    'channels' => [
        'mail' => [
            // Either a literal array or a comma-separated env value.
            'to' => ['admin@example.com', 'devops@example.com'],
            // or: 'to' => env('BACKUP_STATION_MAIL_TO'),
            'from' => env('BACKUP_STATION_MAIL_FROM', env('MAIL_FROM_ADDRESS')),
            'mailer' => env('BACKUP_STATION_MAILER', env('MAIL_MAILER')),
            'queue' => true,    // async by default
        ],
        'slack' => [
            'webhook' => env('BACKUP_STATION_SLACK_WEBHOOK', env('LOG_SLACK_WEBHOOK_URL')),
            'queue' => false,   // webhook is fast; sync is fine
        ],
        'telegram' => [
            'bot_token' => env('BACKUP_STATION_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('BACKUP_STATION_TELEGRAM_CHAT_ID'),
            'queue' => true,    // async by default
        ],
        'discord' => [
            'webhook' => env('BACKUP_STATION_DISCORD_WEBHOOK'),
            'queue' => true,    // async by default
        ],
    ],
],

'retention' => [
    'max_backups' => 30,         // hard cap (0 = unlimited)
    'keep_for_days' => 14,       // delete older than (0 = forever)
    'monthly_keep' => [
        'enabled' => true,
        'day' => 1,              // keep the 1st of each month
        'keep_months' => 12,     // for 12 months
    ],
],
```

The package auto-registers its scheduler — just make sure Laravel's `schedule:run` is wired up (Laravel 11+ does this for you).

## Artisan Commands

```bash
php artisan backup-station:run                 # back up now
php artisan backup-station:run --connection=mysql --note="Pre-deploy"
php artisan backup-station:cleanup             # apply retention policy now
php artisan backup-station:install             # publish config
php artisan backup-station:install --force     # overwrite existing config
```

> Use `--force` when re-running install after a package upgrade if you
> want the published `config/backup-station.php` overwritten with the
> latest defaults. Without `--force`, your existing file is preserved.

## Programmatic API

```php
use MahmoudMhamed\BackupStation\Facades\BackupStation;

BackupStation::runBackup();             // returns created entries
BackupStation::applyRetentionPolicy();  // returns deleted IDs
BackupStation::stats();                 // dashboard stats
```

## Loading Indicator

Long-running operations (creating a backup, importing a backup file,
running retention cleanup) display a full-screen spinner overlay while
the request is in flight, so the user can see the action is in progress
and won't double-submit.

## Dashboard

The dashboard at `/backup-station` shows:
- Total / success / failed counts and disk usage
- Latest backup
- Full list with **Download**, **Rename**, **Pin**, **Delete** actions
- "Run Backup Now" and "Cleanup" buttons
- Search and per-page filtering
- Config viewer page
- About page with the full feature list

## Security

```php
// config/backup-station.php
'viewer' => [
    'password' => env('BACKUP_STATION_PASSWORD'),
    'middleware' => ['web', 'auth'],
    'authorize' => fn ($req) => $req->user()?->isAdmin(),
],
```

When `authorize` is `null`, the dashboard is only reachable in `local` env.

## License

MIT
