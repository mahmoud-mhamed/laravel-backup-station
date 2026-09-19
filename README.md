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
- 🗜️ **ZIP / Gzip / Plain** — backups default to `.sql.zip` (opens with any tool); switch to `.sql.gz` or plain `.sql` via `BACKUP_STATION_ARCHIVE`
- ⬇️ **Download** any backup with one click, or **Download Multiple** — tick several rows (or select all) and get them as a single ZIP
- ✏️ **Rename** backups in place (extension preserved) and **edit the note** on any entry
- 🔍 **Search & filter** by filename, database, status
- 🌑 **Dark mode** with persistent preference
- 🔒 **Password protection** + authorize callback for the dashboard
- 📒 **JSON metadata** stored in `backups.json` next to the SQL files
- 🛠️ **Artisan commands** for manual run / cleanup / install
- 🔁 **Multi-connection** support — back up several DB connections in one run
- 🏢 **Multi-tenant ready** — a `BackupConnectionProvider` supplies dynamic connections (e.g. one database per tenant) at runtime
- 🎯 **Automatic-backup scope** — exclude databases from scheduled runs, or restrict them to a single database; managed from the dashboard or from config
- 🗄️ **Databases page** — live size, tables count, reachability and last successful backup per database, with search / sort / filters and per-row "Backup now"
- 🎛️ **Run-target selector** — back up everything, or pick one database in the Run dialog (with a searchable dropdown when there are many)
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

## Multi-Tenant / Dynamic Connections

When the set of databases is not known at config time (e.g. one database
per tenant), implement the provider contract and register it:

```php
// config/backup-station.php
'connections_provider' => \App\Services\MyBackupConnectionProvider::class,
```

```php
use MahmoudMhamed\BackupStation\Contracts\BackupConnectionProvider;

class MyBackupConnectionProvider implements BackupConnectionProvider
{
    /** Connection names a full run should cover. */
    public function connections(): array
    {
        return ['mysql', ...Tenant::all()->map(fn ($t) => $t->database()->getName())];
    }

    /** DB config for names Laravel doesn't know (same shape as database.connections.*). */
    public function configFor(string $name): ?array
    {
        return Tenant::findByDatabase($name)?->database()->connection();
    }

    /** Human labels shown in the dashboard (dropdowns, Databases page). */
    public function labels(): array
    {
        return ['mysql' => 'Central', /* db name => tenant name, … */];
    }
}
```

Provider-resolved configs are registered into `database.connections` at
runtime, so dumps, restores, table listings and size queries all work on
them transparently. On a full run, one broken database (e.g. a stale
tenant) is recorded as a failed entry and the run continues with the rest.

### Custom route registration

Multi-tenant apps often need the dashboard registered per domain group
(central domains vs tenant domains) with different middleware. Disable
auto-registration and load the routes file yourself:

```php
// config/backup-station.php
'viewer' => ['register_routes' => false, /* … */],

// e.g. bootstrap/app.php
Route::middleware($centralMiddleware)->domain($domain)
    ->group(base_path('vendor/mahmoud-mhamed/laravel-backup-station/routes/web.php'));
Route::middleware($tenantMiddleware)
    ->group(base_path('vendor/mahmoud-mhamed/laravel-backup-station/routes/web.php'));
```

The `backup-station` middleware group and throttle alias are still
registered by the package; `viewer.middleware` applies inside every
registration.

## Automatic Backup Scope

Scheduled runs can skip databases, or be restricted to specific ones.
**Manual runs always ignore this scope** — an explicit target or a manual
"All databases" run covers everything.

```php
// config/backup-station.php
'scope' => [
    // 'ui'     — manage from the dashboard Databases page (stored in
    //            settings.json on the storage disk). Default.
    // 'config' — the arrays below are authoritative; dashboard toggles
    //            are disabled.
    'source' => env('BACKUP_STATION_SCOPE_SOURCE', 'ui'),

    'only' => [],      // when non-empty, scheduled runs cover ONLY these
    'exclude' => [],   // otherwise these are skipped on scheduled runs
],
```

In `ui` mode the Databases page shows per-row **Exclude / Include** and
**Only this** buttons plus a *Will back up / Skipped* badge for each
database.

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

BackupStation::runBackup();                 // manual run — covers every connection
BackupStation::runBackup(scheduled: true);  // respects the automatic-backup scope
BackupStation::runBackup('mysql');          // one explicit connection
BackupStation::applyRetentionPolicy();      // returns deleted IDs
BackupStation::stats();                     // dashboard stats
BackupStation::allBackupConnections();      // every configured connection
BackupStation::backupConnections();         // connections a scheduled run covers
BackupStation::databasesSizeSummary();      // combined live size of all databases
```

## Loading Indicator

Long-running operations (creating a backup, importing a backup file,
running retention cleanup) display a full-screen spinner overlay while
the request is in flight, so the user can see the action is in progress
and won't double-submit.

## Dashboard

The dashboard at `/backup-station` shows:
- Stat cards that mirror the active filters: totals, success rate with
  average duration, sizes (total + average per backup), combined live
  size of every configured database, and the latest backup with the
  first-success date and coverage span
- Full list with **Download**, **Rename**, **Pin**, **Delete** actions
- **Download Multiple** — toggles a checkbox column with select-all; the
  selected backups are bundled into one `backups-<timestamp>.zip`
  (respects `BACKUP_STATION_DOWNLOAD_PASSWORD` when set)
- "Run Backup Now" with a target selector — all databases or a single
  one (searchable dropdown, per-table structure/data picker)
- Search, date range, status and database filters, per-page control
- **Databases** page — one row per configured database with live size,
  tables count, reachability, last successful backup, auto-backup scope
  toggles and a **Backup now** shortcut (`/backup-station?run=<connection>`
  deep-links into the Run dialog preselected)
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

Backups contain raw SQL. When the active storage disk is publicly served
(the `public` disk, or any local disk rooted inside a web folder), the
dashboard shows a prominent warning banner on every page — set
`BACKUP_STATION_DISK` to a private disk (e.g. `local`, `s3`) to clear it.

## License

MIT
