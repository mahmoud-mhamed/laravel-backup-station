<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable in Production / Local
    |--------------------------------------------------------------------------
    */
    'enable_production' => true,
    'enable_local' => true,

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | All destructive / dangerous actions are OFF by default. Opt-in via env.
    |
    | allow_import:
    |     Uploading an arbitrary SQL file is effectively arbitrary code on
    |     your DB — only enable on environments you trust.
    |
    | allow_restore:
    |     Restoring overwrites the live database; same caveat as import.
    |     When enabled, also set `restore_password` below to require an
    |     extra confirmation step in the dashboard.
    |
    | allow_delete:
    |     Deletes the actual backup file from the storage disk.
    |
    */
    'allow_import' => env('BACKUP_STATION_ALLOW_IMPORT', false),
    'allow_delete' => env('BACKUP_STATION_ALLOW_DELETE', true),
    'allow_restore' => env('BACKUP_STATION_ALLOW_RESTORE', false),

    /*
    |--------------------------------------------------------------------------
    | Restore: auto-create the target database
    |--------------------------------------------------------------------------
    |
    | When true, the restore step will issue
    |   CREATE DATABASE IF NOT EXISTS <db>  (MySQL/MariaDB)
    |   CREATE DATABASE <db>                (PostgreSQL, only if missing)
    | before piping the dump in. Useful when restoring onto a fresh server
    | where the schema doesn't exist yet.
    |
    | Charset/collation defaults match Laravel's mysql connection config.
    | Disable if your DB user lacks the CREATE DATABASE privilege.
    |
    */
    'restore_auto_create_database' => (bool) env('BACKUP_STATION_RESTORE_AUTO_CREATE_DB', true),

    /*
    |--------------------------------------------------------------------------
    | Confirmation Passwords
    |--------------------------------------------------------------------------
    |
    | When set, the dashboard requires a separate password (in addition to
    | the regular login gate) before performing the action. Strongly
    | recommended whenever the corresponding allow_* flag is true.
    |
    | Best practice: store a bcrypt hash, e.g.
    |     BACKUP_STATION_RESTORE_PASSWORD='$2y$12$...'
    |     BACKUP_STATION_IMPORT_PASSWORD='$2y$12$...'
    |
    */
    'restore_password' => env('BACKUP_STATION_RESTORE_PASSWORD', null),
    'import_password' => env('BACKUP_STATION_IMPORT_PASSWORD', null),
    'download_password' => env('BACKUP_STATION_DOWNLOAD_PASSWORD', null),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | When `enabled` is true, manual "Run Backup Now" requests from the
    | dashboard dispatch a queued job instead of running synchronously.
    | This is recommended for large databases (the HTTP request returns
    | immediately while the worker handles the dump).
    |
    | The Artisan command (used by the Laravel scheduler) always runs
    | synchronously — your scheduler is already off-request.
    |
    */
    'queue' => [
        'enabled' => (bool) env('BACKUP_STATION_QUEUE', false),
        // When null, falls back to Laravel's queue.default at runtime.
        'connection' => env('BACKUP_STATION_QUEUE_CONNECTION'),
        // When null, the configured connection's default queue is used.
        'queue' => env('BACKUP_STATION_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connections to Backup
    |--------------------------------------------------------------------------
    |
    | List of Laravel database connection names to back up. If left empty,
    | the default connection will be used.
    |
    */
    'connections' => [
        // 'mysql',
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Storage
    |--------------------------------------------------------------------------
    |
    | Where backup files (and the backups.json metadata) are written.
    |
    | disk:
    |     The Laravel filesystem disk to use (must be configured in
    |     config/filesystems.php). Examples: 'local', 'public', 's3',
    |     'minio', 'spaces'. When null, falls back to the application
    |     default disk (config('filesystems.default')).
    |
    | path:
    |     Folder/prefix on the chosen disk where backups are stored.
    |
    */
    'storage' => [
        'disk' => env('BACKUP_STATION_DISK'),
        'path' => env('BACKUP_STATION_PATH', 'backup-station'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filename Format
    |--------------------------------------------------------------------------
    |
    | Available placeholders:
    |   {database}, {connection}, {date}, {time}, {datetime}, {timestamp}
    |
    */
    'filename_format' => 'backup_{database}_{datetime}',

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | When enabled, every backup is wrapped in a password-protected ZIP
    | (AES-256). The user then needs the password to extract the file
    | with any standard archiver (7-Zip / WinRAR / Keka / The Unarchiver).
    |
    | Restoring the backup from the dashboard automatically decrypts it
    | using this same password — keep the value safe.
    |
    | NOTE: Unlike the auth/import/restore passwords, this one must NOT be
    | a bcrypt hash — it has to be reversible to decrypt the archive.
    | Requires PHP's `zip` extension built against libzip 1.2.0+ for
    | AES-256 support (default on most modern systems).
    |
    */
    'encryption' => [
        'enabled' => (bool) env('BACKUP_STATION_ENCRYPT', false),
        'password' => env('BACKUP_STATION_ENCRYPT_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive Format
    |--------------------------------------------------------------------------
    |
    | What to wrap the raw SQL dump in before storing it.
    |
    |   'zip'   — standard ZIP (.sql.zip). DEFAULT. Opens with any archive
    |              tool. If encryption (above) is enabled, this becomes a
    |              password-protected AES-256 ZIP automatically.
    |   'gzip'  — streamed gzip (.sql.gz). Smallest output, no archive
    |              container.
    |   'none'  — plain .sql, uncompressed.
    |
    */
    'archive' => env('BACKUP_STATION_ARCHIVE', 'zip'),

    // Legacy flag — kept only for users who haven't migrated to `archive`.
    // When `archive` is set above this value is ignored.
    'compress' => true,

    /*
    |--------------------------------------------------------------------------
    | Schedules (multiple)
    |--------------------------------------------------------------------------
    |
    | Define one or more independent schedules. Each entry has its own:
    |   - timing      (frequency / time / days / day_of_month / cron)
    |   - target      (connection)
    |   - scope       (tables include/exclude, mode = full|structure|data)
    |   - bookkeeping (note attached to created backups)
    |
    | Each schedule registers as its own Laravel scheduled task and dispatches
    | `php artisan backup-station:run --schedule=<name>` when it fires.
    |
    | Disable a schedule with `enabled => false`. Set the top-level `enabled`
    | of the whole package via the `enable_local`/`enable_production` flags.
    |
    */
    'schedules' => [

        [
            'name' => 'default',
            'enabled' => true,
            'connection' => null,            // null = Laravel default connection
            'frequency' => 'daily',          // daily | twiceDaily | monthly | cron
            'time' => '02:00',
            'days' => ['*'],                 // ['*'] = every day, or ['mon','wed','fri'] / [1,3,5]
            'day_of_month' => 1,             // used by frequency=monthly
            'cron' => null,                  // used by frequency=cron, e.g. '0 */6 * * *'
            'without_overlapping' => true,
            'on_one_server' => true,
            'run_in_background' => false,
            'tables' => [
                'include' => [],
                'exclude' => [
                    // 'sessions', 'cache', 'failed_jobs', 'jobs', 'telescope_entries',
                ],
            ],
            'mode' => 'full',                // full | structure | data
            'note' => null,
        ],

        // Example second schedule — uncomment + adjust:
        // [
        //     'name' => 'hourly-orders',
        //     'enabled' => true,
        //     'frequency' => 'cron',
        //     'cron' => '0 * * * *',
        //     'tables' => ['include' => ['orders', 'order_items']],
        //     'mode' => 'data',
        //     'note' => 'Hourly orders snapshot',
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Controls how backups are pruned. After every successful backup, the
    | retention policy runs and old backups are removed.
    |
    | max_backups:
    |     Hard cap on the total number of backup files. Oldest backups are
    |     deleted first. Set to 0 (or null) to disable this cap.
    |
    | keep_for_days:
    |     Backups older than this many days are deleted — UNLESS they fall
    |     under the monthly_keep rule (see below) or are pinned.
    |     Set to 0 to disable age-based pruning.
    |
    | monthly_keep:
    |     Keep one backup per month from the configured day-of-month.
    |     Useful for long-term archival without keeping every daily backup.
    |
    |     enabled:    Toggle the rule.
    |     day:        Day-of-month to preserve (1-31). Accepts a single int
    |                 (e.g. 1) or an array of days (e.g. [1, 15, 28]). The
    |                 first successful backup matching ANY listed day is
    |                 preserved per (year-month, day) pair.
    |     keep_months: Number of monthly snapshots to retain. Older monthly
    |                  snapshots beyond this count are pruned.
    |
    */
    'retention' => [
        'max_backups' => 30,
        'keep_for_days' => 14,
        'monthly_keep' => [
            'enabled' => true,
            'day' => [1],
            'keep_months' => 12,
        ],

        /*
        | Hard cap on the size of backups.json (the metadata file). When the
        | encoded JSON would exceed this size after a write, the oldest
        | entries are pruned (and their on-disk files deleted) until it fits.
        | Set to 0 to disable.
        */
        'metadata_max_size_kb' => (int) env('BACKUP_STATION_METADATA_MAX_KB', 5 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Binary Paths
    |--------------------------------------------------------------------------
    |
    | Leave these empty (default) and the package will auto-discover the
    | binary. The lookup order is:
    |
    |   1. The value here (or the matching env var) if it exists on disk
    |   2. The system PATH (`which` / `where`)
    |   3. The list of common installation paths in `binary_paths` below
    |
    | Set the absolute path explicitly only if auto-discovery picks the
    | wrong one or fails on your setup.
    |
    */
    'binaries' => [
        'mysqldump' => env('BACKUP_STATION_MYSQLDUMP'),
        'mysql' => env('BACKUP_STATION_MYSQL'),
        'pg_dump' => env('BACKUP_STATION_PG_DUMP'),
        'psql' => env('BACKUP_STATION_PSQL'),
        'sqlite3' => env('BACKUP_STATION_SQLITE3'),
        'gzip' => env('BACKUP_STATION_GZIP'),
        'zip' => env('BACKUP_STATION_ZIP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Common Lookup Paths
    |--------------------------------------------------------------------------
    |
    | Used by the auto-discovery fallback when the binary is not in PATH.
    | First match wins. Add custom locations here if you have an unusual
    | install location.
    |
    */
    'binary_paths' => [
        'mysqldump' => [
            // Linux
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            // MariaDB (modern installs ship `mariadb-dump` only)
            '/usr/bin/mariadb-dump',
            '/usr/local/bin/mariadb-dump',
            // macOS — Homebrew (Apple Silicon)
            '/opt/homebrew/bin/mysqldump',
            '/opt/homebrew/opt/mysql-client/bin/mysqldump',
            '/opt/homebrew/opt/mariadb/bin/mariadb-dump',
            // macOS — Homebrew (Intel)
            '/usr/local/opt/mysql-client/bin/mysqldump',
            '/usr/local/opt/mariadb/bin/mariadb-dump',
            // macOS — MAMP
            '/Applications/MAMP/Library/bin/mysqldump',
            '/Applications/MAMP/Library/bin/mysqldump-5.7',
            '/Applications/MAMP/Library/bin/mysqldump-8.0',
            // Windows — XAMPP / WAMP / Laragon / MySQL official
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0\\bin\\mysqldump.exe',
            'C:\\wamp\\bin\\mysql\\mysql8.0\\bin\\mysqldump.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
            'C:\\Program Files\\MariaDB 10.11\\bin\\mysqldump.exe',
            'C:\\Program Files\\MariaDB 10.6\\bin\\mariadb-dump.exe',
        ],
        'mysql' => [
            // Linux
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            '/usr/bin/mariadb',
            '/usr/local/bin/mariadb',
            // macOS — Homebrew (Apple Silicon)
            '/opt/homebrew/bin/mysql',
            '/opt/homebrew/opt/mysql-client/bin/mysql',
            '/opt/homebrew/opt/mariadb/bin/mariadb',
            // macOS — Homebrew (Intel)
            '/usr/local/opt/mysql-client/bin/mysql',
            '/usr/local/opt/mariadb/bin/mariadb',
            // macOS — MAMP
            '/Applications/MAMP/Library/bin/mysql',
            '/Applications/MAMP/Library/bin/mysql80/bin/mysql',
            // Windows
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0\\bin\\mysql.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysql.exe',
        ],
        'psql' => [
            // Linux
            '/usr/bin/psql',
            '/usr/local/bin/psql',
            '/usr/lib/postgresql/16/bin/psql',
            '/usr/lib/postgresql/15/bin/psql',
            '/usr/lib/postgresql/14/bin/psql',
            '/usr/lib/postgresql/13/bin/psql',
            // macOS
            '/opt/homebrew/bin/psql',
            '/opt/homebrew/opt/postgresql@16/bin/psql',
            '/opt/homebrew/opt/postgresql@15/bin/psql',
            '/usr/local/opt/postgresql/bin/psql',
            '/Applications/Postgres.app/Contents/Versions/latest/bin/psql',
            // Windows
            'C:\\Program Files\\PostgreSQL\\16\\bin\\psql.exe',
            'C:\\Program Files\\PostgreSQL\\15\\bin\\psql.exe',
            'C:\\Program Files\\PostgreSQL\\14\\bin\\psql.exe',
        ],
        'pg_dump' => [
            // Linux
            '/usr/bin/pg_dump',
            '/usr/local/bin/pg_dump',
            // Linux versioned
            '/usr/lib/postgresql/16/bin/pg_dump',
            '/usr/lib/postgresql/15/bin/pg_dump',
            '/usr/lib/postgresql/14/bin/pg_dump',
            '/usr/lib/postgresql/13/bin/pg_dump',
            // macOS — Homebrew
            '/opt/homebrew/bin/pg_dump',
            '/opt/homebrew/opt/postgresql@16/bin/pg_dump',
            '/opt/homebrew/opt/postgresql@15/bin/pg_dump',
            '/opt/homebrew/opt/postgresql@14/bin/pg_dump',
            '/usr/local/opt/postgresql/bin/pg_dump',
            // macOS — Postgres.app
            '/Applications/Postgres.app/Contents/Versions/latest/bin/pg_dump',
            // Windows
            'C:\\Program Files\\PostgreSQL\\16\\bin\\pg_dump.exe',
            'C:\\Program Files\\PostgreSQL\\15\\bin\\pg_dump.exe',
            'C:\\Program Files\\PostgreSQL\\14\\bin\\pg_dump.exe',
            'C:\\laragon\\bin\\postgresql\\bin\\pg_dump.exe',
        ],
        'sqlite3' => [
            '/usr/bin/sqlite3',
            '/usr/local/bin/sqlite3',
            '/opt/homebrew/bin/sqlite3',
            'C:\\Program Files\\SQLite\\sqlite3.exe',
            'C:\\sqlite\\sqlite3.exe',
        ],
        'gzip' => [
            '/usr/bin/gzip',
            '/bin/gzip',
            '/usr/local/bin/gzip',
            '/opt/homebrew/bin/gzip',
        ],
        'zip' => [
            '/usr/bin/zip',
            '/bin/zip',
            '/usr/local/bin/zip',
            '/opt/homebrew/bin/zip',
            'C:\\Program Files\\7-Zip\\7z.exe',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | mysqldump Extra Options
    |--------------------------------------------------------------------------
    */
    'mysqldump_options' => [
        '--single-transaction',
        '--quick',
        '--lock-tables=false',
        '--routines',
        '--triggers',
        '--events',
        '--no-tablespaces',
        '--default-character-set=utf8mb4',
        '--set-gtid-purged=OFF',
    ],

    /*
    |--------------------------------------------------------------------------
    | Process Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => 60 * 30,

    /*
    |--------------------------------------------------------------------------
    | Import
    |--------------------------------------------------------------------------
    |
    | max_upload_kb:    Per-file cap in KB (default 100 MB).
    | total_quota_kb:   Refuse new uploads/backups when the total disk usage
    |                   would exceed this (in KB, default 5 GB). 0 = no cap.
    |
    */
    'import' => [
        'max_upload_kb' => (int) env('BACKUP_STATION_MAX_UPLOAD_KB', 100 * 1024),
        'total_quota_kb' => (int) env('BACKUP_STATION_TOTAL_QUOTA_KB', 5 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Send a message after each backup attempt. Each event (success/failure)
    | can deliver to any combination of channels: log, mail, slack, telegram,
    | discord, or a custom Laravel logging channel.
    |
    | mail.to / slack.webhook / telegram.bot_token + chat_id /
    | discord.webhook are read once at boot.
    |
    */
    'notifications' => [
        'on_success' => [
            'enabled' => true,
            'channels' => ['log'],
        ],
        'on_failure' => [
            'enabled' => true,
            'channels' => ['log', 'mail'],
        ],

        'channels' => [
            'log' => [
                // Falls back to the app's default log channel (LOG_CHANNEL).
                'channel' => env('BACKUP_STATION_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
            ],

            'mail' => [
                // Recipients — accepts either a comma-separated string from
                // env, or a literal PHP array. Examples:
                //   'to' => 'admin@example.com,devops@example.com',
                //   'to' => ['admin@example.com', 'devops@example.com'],
                'to' => env('BACKUP_STATION_MAIL_TO', []),
                // Falls back to MAIL_FROM_ADDRESS / MAIL_MAILER from your app.
                'from' => env('BACKUP_STATION_MAIL_FROM', env('MAIL_FROM_ADDRESS')),
                'mailer' => env('BACKUP_STATION_MAILER', env('MAIL_MAILER')),
                'queue' => (bool) env('BACKUP_STATION_MAIL_QUEUE', true),
            ],

            'slack' => [
                // Falls back to the standard Laravel Slack webhook env.
                'webhook' => env('BACKUP_STATION_SLACK_WEBHOOK', env('LOG_SLACK_WEBHOOK_URL')),
                'username' => env('BACKUP_STATION_SLACK_USERNAME', env('LOG_SLACK_USERNAME', 'Backup Station')),
                'emoji' => env('BACKUP_STATION_SLACK_EMOJI', env('LOG_SLACK_EMOJI', ':floppy_disk:')),
                'queue' => (bool) env('BACKUP_STATION_SLACK_QUEUE', false),
            ],

            'telegram' => [
                'bot_token' => env('BACKUP_STATION_TELEGRAM_BOT_TOKEN'),
                'chat_id' => env('BACKUP_STATION_TELEGRAM_CHAT_ID'),
                'queue' => (bool) env('BACKUP_STATION_TELEGRAM_QUEUE', true),
            ],

            'discord' => [
                'webhook' => env('BACKUP_STATION_DISCORD_WEBHOOK'),
                'queue' => (bool) env('BACKUP_STATION_DISCORD_QUEUE', true),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Viewer
    |--------------------------------------------------------------------------
    */
    'viewer' => [

        // Enable or disable the dashboard routes
        'enabled' => env('BACKUP_STATION_VIEWER_ENABLED', true),

        // Simple password protection. Set to null to disable.
        'password' => env('BACKUP_STATION_PASSWORD', null),

        // Rate-limit "<max-attempts>,<minutes>" applied to POST routes.
        // login    — brute-force protection on /login
        // action   — bound on /run, /import (general write actions)
        // restore  — strict bound on the most dangerous endpoint
        'login_throttle' => env('BACKUP_STATION_LOGIN_THROTTLE', '5,1'),
        'action_throttle' => env('BACKUP_STATION_ACTION_THROTTLE', '10,1'),
        'restore_throttle' => env('BACKUP_STATION_RESTORE_THROTTLE', '3,5'),

        // URL prefix (e.g. /backup-station)
        'route_prefix' => 'backup-station',

        // Middleware applied to dashboard routes. Defaults to ['web', 'auth']
        // so anonymous visitors are redirected to the app's login page.
        // Combine with `password` below for an extra dashboard-specific gate,
        // or replace `auth` with your own gate (e.g. 'auth:admin').
        'middleware' => ['web', 'auth'],

        // Per-row file existence check on the listing page.
        //   true  = always check (one HEAD request per row on remote disks)
        //   false = never check
        //   null  = auto: check on local disks, skip on remote (S3 etc.)
        'check_files_exist' => env('BACKUP_STATION_CHECK_FILES_EXIST', null),

        // Items per page on the backup list
        'per_page' => 25,

        'per_page_options' => [10, 25, 50, 100],
    ],

];
