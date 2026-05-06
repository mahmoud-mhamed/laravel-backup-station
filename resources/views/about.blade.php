<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>About - Backup Station</title>
    @include('backup-station::partials.styles')
    <script>@include('backup-station::partials.theme-js')</script>
    <style>
        .hero { padding: 32px; background: linear-gradient(135deg, var(--primary-light), transparent); border-radius: var(--radius-lg); border: 1px solid var(--border); margin-bottom: 24px; }
        .hero h1 { font-size: 28px; letter-spacing: -0.02em; margin-bottom: 6px; }
        .hero p { color: var(--text-muted); font-size: 14px; max-width: 720px; line-height: 1.6; }
        .hero .pills { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 14px; }
        .hero .pills span { background: var(--bg-card); border: 1px solid var(--border); padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; color: var(--text-muted); }

        .section { margin-top: 28px; }
        .section-title { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 12px; padding-left: 4px; }

        .feature-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
        .feature { padding:18px; border:1px solid var(--border); border-radius:var(--radius); background:var(--bg-card); transition: all 0.2s; }
        .feature:hover { border-color: var(--primary); box-shadow: var(--shadow); transform: translateY(-1px); }
        .feature .icon { display: inline-flex; width: 32px; height: 32px; align-items: center; justify-content: center; background: var(--primary-light); color: var(--primary); border-radius: var(--radius-sm); font-size: 16px; margin-bottom: 10px; }
        .feature h4 { font-size: 14px; margin-bottom: 6px; color: var(--text); font-weight: 600; }
        .feature p { font-size: 13px; color: var(--text-muted); line-height: 1.55; }

        kbd { font-family: var(--font-mono); font-size: 12px; background: var(--bg); padding: 2px 7px; border-radius: 4px; border: 1px solid var(--border); color: var(--text); }
        ol.steps { margin: 12px 0 0 20px; font-size: 13px; line-height: 2; color: var(--text-muted); }
        ol.steps li strong { color: var(--text); font-weight: 600; }

        .channel-row { display: flex; gap: 10px; align-items: center; padding: 10px 14px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); background: var(--bg); margin-bottom: 6px; font-size: 13px; }
        .channel-row .ch { font-weight: 600; min-width: 80px; }
        .channel-row .env { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); }
    </style>
</head>
<body>
<div class="layout">
    @include('backup-station::partials.nav')
    <div class="container">

        <div class="hero">
            <h1>Backup Station</h1>
            <p>Automatic, scheduled database backups for Laravel — with retention rules, marking,
               import/restore, and a beautiful zero-config dashboard. Storage is pluggable, so backups
               can land on local disk, S3, MinIO, DigitalOcean Spaces, or any Laravel filesystem.</p>
            <div class="pills">
                <span>Laravel 10 → 13</span>
                <span>PHP 8.1+</span>
                <span>MySQL · MariaDB · PostgreSQL · SQLite</span>
                <span>Local · S3 · MinIO · Spaces</span>
            </div>
        </div>

        {{-- ========== Core ========== --}}
        <div class="section">
            <div class="section-title">Backup &amp; Restore</div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="icon">⏱</span>
                    <h4>Scheduled Backups</h4>
                    <p>Daily / twice-daily / monthly / custom cron — runs through Laravel's scheduler with
                       <kbd>withoutOverlapping</kbd> and <kbd>onOneServer</kbd> by default.</p>
                </div>
                <div class="feature">
                    <span class="icon">⚡</span>
                    <h4>Queueable Backups</h4>
                    <p>Optionally dispatch dashboard "Run Backup Now" requests onto a Laravel queue
                       (<kbd>RunBackupJob</kbd>) so HTTP requests return instantly while a worker handles
                       the dump.</p>
                </div>
                <div class="feature">
                    <span class="icon">📅</span>
                    <h4>Days-of-Week Filter</h4>
                    <p>Pick exactly which weekdays the backup runs — every day, weekdays only, or any
                       custom subset like Sun/Wed/Fri.</p>
                </div>
                <div class="feature">
                    <span class="icon">↺</span>
                    <h4>One-Click Restore</h4>
                    <p>Restore any successful backup straight back into its source database. Streams from
                       S3 / local, decompresses, and pipes through the matching SQL client.</p>
                </div>
                <div class="feature">
                    <span class="icon">⬆</span>
                    <h4>Import / Upload</h4>
                    <p>Already have a backup file? Upload <kbd>.sql</kbd>, <kbd>.sql.gz</kbd>, <kbd>.gz</kbd>
                       or <kbd>.zip</kbd> from the dashboard and it's registered like any other backup.</p>
                </div>
                <div class="feature">
                    <span class="icon">🐬</span>
                    <h4>Multi-Driver Dumps</h4>
                    <p>Native dumpers for MySQL/MariaDB (<kbd>mysqldump</kbd>), PostgreSQL
                       (<kbd>pg_dump</kbd>), and SQLite (<kbd>sqlite3</kbd>).</p>
                </div>
                <div class="feature">
                    <span class="icon">📋</span>
                    <h4>Selective Tables</h4>
                    <p>Whitelist specific tables, or skip noisy ones (logs / sessions / cache /
                       failed_jobs) so backups stay small and fast.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔌</span>
                    <h4>Multi-Connection</h4>
                    <p>Back up several Laravel database connections in a single run — each gets its own
                       file and metadata entry.</p>
                </div>
            </div>
        </div>

        {{-- ========== Storage ========== --}}
        <div class="section">
            <div class="section-title">Storage</div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="icon">☁</span>
                    <h4>Pluggable Filesystem</h4>
                    <p>Save to any Laravel disk: <kbd>local</kbd>, <kbd>s3</kbd>, <kbd>minio</kbd>,
                       <kbd>spaces</kbd>… Defaults to <kbd>filesystems.default</kbd> when not configured.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔐</span>
                    <h4>AES-256 Encryption</h4>
                    <p>Optionally wrap every backup in a password-protected ZIP. The downloaded file
                       requires the password to extract with any standard archiver
                       (7-Zip / WinRAR / Keka). Restore from the dashboard auto-decrypts.</p>
                </div>
                <div class="feature">
                    <span class="icon">🗜</span>
                    <h4>Archive Formats</h4>
                    <p>Choose between <kbd>zip</kbd> (default — opens with any tool), streamed
                       <kbd>gzip</kbd>, or plain SQL. Restoring auto-detects the format.</p>
                </div>
                <div class="feature">
                    <span class="icon">🗂</span>
                    <h4>JSON Metadata</h4>
                    <p>All bookkeeping lives in a single <kbd>backups.json</kbd> on the same disk —
                       portable, inspectable, no database table required.</p>
                </div>
                <div class="feature">
                    <span class="icon">⚠</span>
                    <h4>Missing-File Detection</h4>
                    <p>If a backup file is removed out-of-band, the dashboard shows it as
                       <strong>Missing</strong> and hides the download/restore actions.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔎</span>
                    <h4>Binary Auto-Discovery</h4>
                    <p>Locates <kbd>mysqldump</kbd>, <kbd>mysql</kbd>, <kbd>pg_dump</kbd>, <kbd>psql</kbd>
                       across the system PATH and common install paths (Homebrew, MAMP, XAMPP, WAMP,
                       Laragon, Postgres.app, Linux distros, Windows).</p>
                </div>
            </div>
        </div>

        {{-- ========== Retention ========== --}}
        <div class="section">
            <div class="section-title">Retention &amp; Organization</div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="icon">#</span>
                    <h4>Max Copies Cap</h4>
                    <p>Hard limit on the total number of backup files. Oldest are pruned first while
                       marked and monthly snapshots are protected.</p>
                </div>
                <div class="feature">
                    <span class="icon">⏳</span>
                    <h4>Age-Based Pruning</h4>
                    <p>Auto-delete backups older than X days. Marked and monthly snapshots are excluded
                       from this rule.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔮</span>
                    <h4>Retention Forecast</h4>
                    <p>A dedicated <kbd>/forecast</kbd> page tells you exactly which backups will be
                       deleted in the next N days, why, and how much disk you'll free.</p>
                </div>
                <div class="feature">
                    <span class="icon">📏</span>
                    <h4>Metadata Size Cap</h4>
                    <p>Hard cap on <kbd>backups.json</kbd> (default <strong>5 MB</strong>). When the file
                       grows past it, the oldest entries — and their backup files — are pruned automatically.
                       Marked &amp; monthly snapshots are protected.</p>
                </div>
                <div class="feature">
                    <span class="icon">🗓</span>
                    <h4>Monthly Keep Rule</h4>
                    <p>Pick a day-of-month (e.g. the 1st) and keep that snapshot for N months — perfect
                       for long-term archival without keeping every daily backup.</p>
                </div>
                <div class="feature">
                    <span class="icon">★</span>
                    <h4>Mark Important Backups</h4>
                    <p>Click the star next to a backup to mark it. Marked backups are <strong>never</strong>
                       deleted by retention rules — keep critical snapshots forever.</p>
                </div>
                <div class="feature">
                    <span class="icon">✏</span>
                    <h4>Rename</h4>
                    <p>Rename any backup in place. The extension (<kbd>.sql</kbd> / <kbd>.sql.gz</kbd>) is
                       preserved automatically.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔍</span>
                    <h4>Search &amp; Filter</h4>
                    <p>Filter by status (success/failed), mark state, date range, or search by filename,
                       database, or note.</p>
                </div>
            </div>
        </div>

        {{-- ========== Notifications ========== --}}
        <div class="section">
            <div class="section-title">Notifications</div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="icon">📧</span>
                    <h4>Mail</h4>
                    <p>HTML email with full context (file, db, driver, size, error). Falls back to your
                       app's <kbd>MAIL_FROM_ADDRESS</kbd> and <kbd>MAIL_MAILER</kbd>.</p>
                </div>
                <div class="feature">
                    <span class="icon">💬</span>
                    <h4>Slack Webhook</h4>
                    <p>Color-coded incoming-webhook messages (green for success, red for failure) with
                       structured fields: filename, database, driver, size, error.
                       Reuses <kbd>LOG_SLACK_WEBHOOK_URL</kbd> if you don't set a dedicated one.
                       Test it with <kbd>php artisan backup-station:test-notification slack</kbd>.</p>
                </div>
                <div class="feature">
                    <span class="icon">✈</span>
                    <h4>Telegram</h4>
                    <p>Markdown messages via Bot API.</p>
                </div>
                <div class="feature">
                    <span class="icon">🎮</span>
                    <h4>Discord</h4>
                    <p>Rich embeds via webhook with status color and timestamp.</p>
                </div>
                <div class="feature">
                    <span class="icon">📒</span>
                    <h4>Log Channel</h4>
                    <p>Default fallback — writes a structured line to your configured Laravel log
                       channel (<kbd>LOG_CHANNEL</kbd>).</p>
                </div>
                <div class="feature">
                    <span class="icon">🔔</span>
                    <h4>Per-Event Routing</h4>
                    <p>Pick channels independently per event — e.g. log every success but mail+slack only
                       on failure.</p>
                </div>
                <div class="feature">
                    <span class="icon">🚀</span>
                    <h4>Async Delivery</h4>
                    <p>Mail / Telegram / Discord notifications are dispatched onto the queue by default
                       (<kbd>afterResponse</kbd>), so the dashboard never blocks while a webhook is slow.
                       Toggle each channel's <kbd>queue</kbd> flag to switch back to sync.</p>
                </div>
            </div>
        </div>

        {{-- ========== UX & Security ========== --}}
        <div class="section">
            <div class="section-title">UX &amp; Security</div>
            <div class="feature-grid">
                <div class="feature">
                    <span class="icon">⏳</span>
                    <h4>Loading Indicator</h4>
                    <p>Long-running operations (backup, restore, import, cleanup) show a full-screen
                       spinner overlay so you can't accidentally double-submit.</p>
                </div>
                <div class="feature">
                    <span class="icon">⚠</span>
                    <h4>Confirm Dialogs</h4>
                    <p>Destructive actions (delete, cleanup, restore) prompt with a styled confirmation
                       dialog showing exactly what will happen.</p>
                </div>
                <div class="feature">
                    <span class="icon">🐞</span>
                    <h4>Failure Reasons</h4>
                    <p>Failed backups expose a <strong>View error</strong> button that opens the full
                       stack message in a dialog.</p>
                </div>
                <div class="feature">
                    <span class="icon">🌑</span>
                    <h4>Dark Mode</h4>
                    <p>Full light/dark theme with persistent preference per browser.</p>
                </div>
                <div class="feature">
                    <span class="icon">🔒</span>
                    <h4>Auth + Password Gate</h4>
                    <p>Defaults to <kbd>['web', 'auth']</kbd> middleware so anonymous users hit your app's
                       login. Add an extra <kbd>BACKUP_STATION_PASSWORD</kbd> for a dashboard-specific
                       gate, or a <kbd>RESTORE_PASSWORD</kbd> / <kbd>IMPORT_PASSWORD</kbd> for the most
                       dangerous actions.</p>
                </div>
                <div class="feature">
                    <span class="icon">⏲</span>
                    <h4>Per-Route Rate Limits</h4>
                    <p>Login (5/min), write actions (10/min), restore (3/5min) — all overridable via
                       <kbd>BACKUP_STATION_*_THROTTLE</kbd> env vars.</p>
                </div>
                <div class="feature">
                    <span class="icon">🚦</span>
                    <h4>Capability Toggles</h4>
                    <p>Disable individual features per environment with
                       <kbd>BACKUP_STATION_ALLOW_IMPORT</kbd>,
                       <kbd>ALLOW_DELETE</kbd>,
                       <kbd>ALLOW_RESTORE</kbd>.</p>
                </div>
            </div>
        </div>

        {{-- ========== Quick Start ========== --}}
        <div class="section">
            <div class="card card-pad">
                <div class="section-title" style="margin-bottom:6px">Quick Start</div>
                <ol class="steps">
                    <li><kbd>composer require mahmoud-mhamed/laravel-backup-station</kbd></li>
                    <li><kbd>php artisan backup-station:install</kbd>
                        <span class="muted">— or <kbd>--force</kbd> to overwrite the published config after an upgrade</span></li>
                    <li>Edit <kbd>config/backup-station.php</kbd> to tweak schedule, retention, storage disk &amp; notifications</li>
                    <li>Run a backup manually: <kbd>php artisan backup-station:run</kbd></li>
                    <li>Open <kbd>/backup-station</kbd> in your browser</li>
                </ol>
            </div>
        </div>

    </div>
</div>
</body>
</html>
