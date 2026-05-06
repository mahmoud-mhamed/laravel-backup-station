<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Config - Backup Station</title>
    @include('backup-station::partials.styles')
    <script>@include('backup-station::partials.theme-js')</script>
    <style>
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; }
        .cfg-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .cfg-card .head { padding: 14px 18px; border-bottom: 1px solid var(--border); background: linear-gradient(135deg, var(--primary-light), transparent); display: flex; align-items: center; gap: 10px; }
        .cfg-card .head .icon { width: 30px; height: 30px; border-radius: var(--radius-sm); background: var(--bg-card); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 14px; box-shadow: var(--shadow-sm); }
        .cfg-card .head h3 { font-size: 14px; font-weight: 600; }
        .cfg-card .head .sub { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

        .cfg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cfg-table td { padding: 9px 18px; border-bottom: 1px solid var(--border-light); vertical-align: top; }
        .cfg-table tr:last-child td { border-bottom: none; }
        .cfg-table td:first-child { color: var(--text-muted); width: 50%; font-weight: 500; }
        .cfg-table td code { font-family: var(--font-mono); font-size: 12px; background: var(--bg); padding: 2px 8px; border-radius: 4px; color: var(--text); }

        .badge-on  { background: var(--success-bg); color: var(--success-text); }
        .badge-off { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); }
        .badge-pill { display:inline-flex; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }

        .chips { display: flex; gap: 4px; flex-wrap: wrap; }
        .chip { background: var(--bg); border: 1px solid var(--border); padding: 2px 8px; border-radius: 999px; font-size: 11px; font-family: var(--font-mono); color: var(--text-muted); }
        .chip.on { background: var(--primary-light); color: var(--primary); border-color: var(--primary); }

        .footer-note { margin-top: 24px; padding: 14px 18px; background: var(--info-bg); border: 1px solid var(--info-border); border-radius: var(--radius); font-size: 13px; color: var(--info-text); }
    </style>
</head>
<body>
@php
    $on  = '<span class="badge-pill badge-on">On</span>';
    $off = '<span class="badge-pill badge-off">Off</span>';

    $bool = fn ($v) => $v ? $on : $off;

    $schedules = (array) ($config['schedules'] ?? []);
    $retention = $config['retention'];
    $monthly = $retention['monthly_keep'];
    $storage = $config['storage'];
    $notif = $config['notifications'];

    $allDays = ['sun','mon','tue','wed','thu','fri','sat'];

    $resolveDay = function ($d) {
        if (is_int($d) || ctype_digit((string)$d)) {
            $map = [0=>'sun',1=>'mon',2=>'tue',3=>'wed',4=>'thu',5=>'fri',6=>'sat'];
            return $map[(int)$d] ?? null;
        }
        return strtolower(substr((string)$d, 0, 3));
    };
@endphp

<div class="layout">
    @include('backup-station::partials.nav')

    <div class="container">

        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; flex-wrap:wrap; gap:10px;">
            <div>
                <h2 style="font-size:22px; letter-spacing:-0.02em;">Configuration</h2>
                <p class="muted" style="margin-top:4px">Snapshot of <code>config/backup-station.php</code>. Edit that file to make changes.</p>
            </div>
            <a href="{{ route('backup-station.index') }}" class="btn">← Back to Backups</a>
        </div>

        <div class="cfg-grid">

            {{-- Schedules (multiple) --}}
            <div class="cfg-card" style="grid-column: 1 / -1;">
                <div class="head">
                    <div class="icon">⏱</div>
                    <div>
                        <h3>Schedules</h3>
                        <div class="sub">{{ count($schedules) }} configured · each runs independently</div>
                    </div>
                </div>

                @forelse($schedules as $s)
                    @php
                        $sDays = (array) ($s['days'] ?? ['*']);
                        $every = in_array('*', $sDays, true);
                        $active = array_map($resolveDay, $sDays);
                        $inc = (array)($s['tables']['include'] ?? []);
                        $exc = (array)($s['tables']['exclude'] ?? []);
                    @endphp
                    <table class="cfg-table" style="border-bottom:1px solid var(--border)">
                        <tr>
                            <td colspan="2" style="padding:14px 18px;background:var(--bg)">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <strong>{{ $s['name'] ?? 'unnamed' }}</strong>
                                    {!! $bool($s['enabled'] ?? false) !!}
                                    <span class="chip">{{ $s['frequency'] ?? 'daily' }}</span>
                                    <span class="chip">mode: {{ $s['mode'] ?? 'full' }}</span>
                                    @if(!empty($s['note']))<span class="muted" style="font-size:11px">— {{ $s['note'] }}</span>@endif
                                </div>
                            </td>
                        </tr>
                        <tr><td>Connection</td><td><code>{{ $s['connection'] ?: config('database.default') }}</code></td></tr>
                        <tr><td>Time</td><td><code>{{ $s['time'] ?? '-' }}</code></td></tr>
                        <tr>
                            <td>Days</td>
                            <td>
                                <div class="chips">
                                    @foreach($allDays as $d)
                                        <span class="chip {{ $every || in_array($d, $active, true) ? 'on' : '' }}">{{ ucfirst($d) }}</span>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                        @if(($s['frequency'] ?? null) === 'monthly')
                            <tr><td>Day of month</td><td><code>{{ $s['day_of_month'] ?? '-' }}</code></td></tr>
                        @endif
                        @if(($s['frequency'] ?? null) === 'cron')
                            <tr><td>Cron expression</td><td><code>{{ $s['cron'] ?? '-' }}</code></td></tr>
                        @endif
                        <tr>
                            <td>Tables — include</td>
                            <td>
                                @if($inc)<div class="chips">@foreach($inc as $t)<span class="chip on">{{ $t }}</span>@endforeach</div>
                                @else<span class="muted">all tables</span>@endif
                            </td>
                        </tr>
                        <tr>
                            <td>Tables — exclude</td>
                            <td>
                                @if($exc)<div class="chips">@foreach($exc as $t)<span class="chip">{{ $t }}</span>@endforeach</div>
                                @else<span class="muted">none</span>@endif
                            </td>
                        </tr>
                    </table>
                @empty
                    <div class="cfg-table" style="padding:14px 18px"><span class="muted">No schedules configured.</span></div>
                @endforelse
            </div>

            {{-- Retention --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">⏳</div>
                    <div>
                        <h3>Retention</h3>
                        <div class="sub">How old backups are pruned</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr><td>Max backups</td><td><code>{{ $retention['max_backups'] ?: 'unlimited' }}</code></td></tr>
                    <tr><td>Keep for days</td><td><code>{{ $retention['keep_for_days'] ? $retention['keep_for_days'] . ' days' : 'forever' }}</code></td></tr>
                    <tr><td>Monthly keep</td><td>{!! $bool($monthly['enabled'] ?? false) !!}</td></tr>
                    @php
                        $monthlyDays = array_values(array_filter(
                            array_map('intval', (array) ($monthly['day'] ?? [1])),
                            fn ($d) => $d >= 1 && $d <= 31
                        ));
                        sort($monthlyDays);
                    @endphp
                    <tr><td>Monthly day(s)</td><td><code>{{ $monthlyDays ? 'day ' . implode(', ', $monthlyDays) : '—' }}</code> of each month</td></tr>
                    <tr><td>Monthly snapshots kept</td><td><code>{{ $monthly['keep_months'] ?? 0 }} months</code></td></tr>
                    <tr>
                        <td>Metadata size cap</td>
                        <td>
                            @if(($retention['metadata_max_size_kb'] ?? 0) > 0)
                                <code>{{ number_format($retention['metadata_max_size_kb'] / 1024, 1) }} MB</code>
                                <div class="muted" style="font-size:11px;margin-top:4px">Oldest entries (and their files) are pruned when <code>backups.json</code> grows past this size.</div>
                            @else
                                <span class="muted">disabled</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>

            {{-- Queue --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">⚡</div>
                    <div>
                        <h3>Queue</h3>
                        <div class="sub">Background execution for "Run Backup Now"</div>
                    </div>
                    <div style="margin-left:auto">{!! $bool($config['queue']['enabled'] ?? false) !!}</div>
                </div>
                @php
                    $qConn = $config['queue']['connection'] ?: config('queue.default');
                    $qName = $config['queue']['queue']
                        ?: config("queue.connections.{$qConn}.queue", 'default');
                    $usingDefaultConn = empty($config['queue']['connection']);
                    $usingDefaultQueue = empty($config['queue']['queue']);
                @endphp
                <table class="cfg-table">
                    <tr>
                        <td>Connection</td>
                        <td>
                            <code>{{ $qConn ?: '—' }}</code>
                            @if($usingDefaultConn)
                                <span class="muted" style="font-size:11px;margin-left:6px">(from <code>queue.default</code>)</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td>Queue name</td>
                        <td>
                            <code>{{ $qName }}</code>
                            @if($usingDefaultQueue)
                                <span class="muted" style="font-size:11px;margin-left:6px">(default for connection)</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="muted" style="font-size:11px">
                            When enabled, dashboard backups dispatch <code>RunBackupJob</code> instead of running synchronously. The Artisan/scheduled command is unaffected.
                        </td>
                    </tr>
                </table>
            </div>

            {{-- Throttles --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">🚦</div>
                    <div>
                        <h3>Rate Limits</h3>
                        <div class="sub">Throttle "<em>attempts,minutes</em>"</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr><td>Login</td><td><code>{{ $config['viewer']['login_throttle'] ?? '5,1' }}</code></td></tr>
                    <tr><td>Write actions <span class="muted">(run/import/delete/rename/pin/cleanup)</span></td><td><code>{{ $config['viewer']['action_throttle'] ?? '10,1' }}</code></td></tr>
                    <tr><td>Restore</td><td><code>{{ $config['viewer']['restore_throttle'] ?? '3,5' }}</code></td></tr>
                </table>
            </div>

            {{-- Storage --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">☁</div>
                    <div>
                        <h3>Storage</h3>
                        <div class="sub">Where backup files are written</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr>
                        <td>Disk</td>
                        <td>
                            <code>{{ $storage['disk'] ?: config('filesystems.default') }}</code>
                            @if(!$storage['disk'])
                                <div class="muted" style="font-size:11px;margin-top:4px">default from <code>filesystems.default</code></div>
                            @endif
                        </td>
                    </tr>
                    <tr><td>Path / prefix</td><td><code>{{ $storage['path'] ?? 'backup-station' }}</code></td></tr>
                    <tr><td>Filename format</td><td><code>{{ $config['filename_format'] }}</code></td></tr>
                    <tr>
                        <td>Archive format</td>
                        <td>
                            @php
                                $svc = app(\MahmoudMhamed\BackupStation\BackupStationService::class);
                                $fmt = $svc->archiveFormat();
                                $label = match ($fmt) {
                                    'encrypted-zip' => 'ZIP (AES-256 encrypted)',
                                    'zip' => 'ZIP',
                                    'gzip' => 'Gzip (.sql.gz)',
                                    default => 'Plain SQL',
                                };
                            @endphp
                            <code>{{ $label }}</code>
                        </td>
                    </tr>
                    <tr>
                        <td>AES-256 encryption</td>
                        <td>
                            @php $encOn = !empty($config['encryption']['enabled']) && !empty($config['encryption']['password']); @endphp
                            {!! $bool($encOn) !!}
                            @if(!empty($config['encryption']['enabled']) && empty($config['encryption']['password']))
                                <div class="muted" style="font-size:11px;color:var(--warning-text);margin-top:4px">enabled but no password set — encryption is inactive</div>
                            @endif
                        </td>
                    </tr>
                    <tr><td>Process timeout</td><td><code>{{ $config['timeout'] ?? 1800 }}s</code></td></tr>
                </table>
            </div>

            {{-- Connections --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">🔌</div>
                    <div>
                        <h3>Database Connections</h3>
                        <div class="sub">Which Laravel connections are backed up</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr>
                        <td>Configured</td>
                        <td>
                            <div class="chips">
                                @foreach(($config['connections'] ?: [config('database.default')]) as $c)
                                    <span class="chip on">{{ $c }}</span>
                                @endforeach
                                @if(empty($config['connections']))
                                    <span class="muted" style="font-size:11px">(default)</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            {{-- Permissions --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">🚦</div>
                    <div>
                        <h3>Permissions</h3>
                        <div class="sub">Dashboard capability toggles</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr><td>Allow Import</td><td>{!! $bool($config['allow_import'] ?? true) !!}</td></tr>
                    <tr><td>Allow Delete</td><td>{!! $bool($config['allow_delete'] ?? true) !!}</td></tr>
                    <tr><td>Allow Restore</td><td>{!! $bool($config['allow_restore'] ?? true) !!}</td></tr>
                    <tr><td>Max upload size</td><td><code>{{ number_format(($config['import']['max_upload_kb'] ?? 0) / 1024, 0) }} MB</code></td></tr>
                    <tr><td>Import password</td><td>{!! !empty($config['import_password']) ? $on : $off !!}</td></tr>
                    <tr><td>Restore password</td><td>{!! !empty($config['restore_password']) ? $on : $off !!}</td></tr>
                    <tr><td>Download password</td><td>{!! !empty($config['download_password']) ? $on : $off !!}</td></tr>
                </table>
            </div>

            {{-- Viewer --}}
            <div class="cfg-card">
                <div class="head">
                    <div class="icon">👁</div>
                    <div>
                        <h3>Dashboard</h3>
                        <div class="sub">Web viewer settings</div>
                    </div>
                    <div style="margin-left:auto">{!! $bool($config['viewer']['enabled'] ?? true) !!}</div>
                </div>
                <table class="cfg-table">
                    <tr><td>Route prefix</td><td><code>/{{ ltrim($config['viewer']['route_prefix'] ?? 'backup-station', '/') }}</code></td></tr>
                    <tr><td>Middleware</td><td>
                        <div class="chips">
                            @foreach((array)($config['viewer']['middleware'] ?? ['web']) as $m)
                                <span class="chip">{{ $m }}</span>
                            @endforeach
                        </div>
                    </td></tr>
                    <tr><td>Password protection</td><td>{!! !empty($config['viewer']['password']) ? $on : $off !!}</td></tr>
                    <tr><td>Per-page default</td><td><code>{{ $config['viewer']['per_page'] ?? 25 }}</code></td></tr>
                </table>
            </div>

            {{-- Notifications --}}
            <div class="cfg-card" style="grid-column: 1 / -1;">
                <div class="head">
                    <div class="icon">🔔</div>
                    <div>
                        <h3>Notifications</h3>
                        <div class="sub">Where success / failure messages are sent</div>
                    </div>
                </div>
                <table class="cfg-table">
                    <tr>
                        <td>On Success</td>
                        <td>
                            {!! $bool($notif['on_success']['enabled'] ?? false) !!}
                            <div class="chips" style="margin-top:6px">
                                @foreach((array)($notif['on_success']['channels'] ?? []) as $c)
                                    <span class="chip on">{{ $c }}</span>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>On Failure</td>
                        <td>
                            {!! $bool($notif['on_failure']['enabled'] ?? false) !!}
                            <div class="chips" style="margin-top:6px">
                                @foreach((array)($notif['on_failure']['channels'] ?? []) as $c)
                                    <span class="chip on">{{ $c }}</span>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Log channel</td>
                        <td><code>{{ $notif['channels']['log']['channel'] ?? '-' }}</code></td>
                    </tr>
                    @php
                        $rawTo = $notif['channels']['mail']['to'] ?? [];
                        if (is_string($rawTo)) $rawTo = explode(',', $rawTo);
                        $mailTo = array_values(array_filter(array_map('trim', (array)$rawTo)));
                        $qBadge = fn ($q) => $q ? '<span class="chip on" style="margin-left:6px">queued</span>' : '<span class="chip" style="margin-left:6px">sync</span>';
                    @endphp
                    <tr>
                        <td>Mail recipients</td>
                        <td>
                            @if($mailTo)
                                <div class="chips">
                                    @foreach($mailTo as $addr) <span class="chip">{{ $addr }}</span> @endforeach
                                </div>
                            @else
                                <span class="muted">(not configured)</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td>Mail delivery</td>
                        <td>
                            From <code>{{ $notif['channels']['mail']['from'] ?? '-' }}</code>
                            via mailer <code>{{ $notif['channels']['mail']['mailer'] ?? config('mail.default') }}</code>
                            {!! $qBadge(!empty($notif['channels']['mail']['queue'])) !!}
                        </td>
                    </tr>
                    <tr>
                        <td>Slack webhook</td>
                        <td>{!! !empty($notif['channels']['slack']['webhook']) ? $on : $off !!} {!! $qBadge(!empty($notif['channels']['slack']['queue'])) !!}</td>
                    </tr>
                    <tr>
                        <td>Telegram</td>
                        <td>{!! (!empty($notif['channels']['telegram']['bot_token']) && !empty($notif['channels']['telegram']['chat_id'])) ? $on : $off !!} {!! $qBadge(!empty($notif['channels']['telegram']['queue'])) !!}</td>
                    </tr>
                    <tr>
                        <td>Discord webhook</td>
                        <td>{!! !empty($notif['channels']['discord']['webhook']) ? $on : $off !!} {!! $qBadge(!empty($notif['channels']['discord']['queue'])) !!}</td>
                    </tr>
                </table>
            </div>

            {{-- Binaries --}}
            <div class="cfg-card" style="grid-column: 1 / -1;">
                <div class="head">
                    <div class="icon">⚙</div>
                    <div>
                        <h3>Binaries</h3>
                        <div class="sub">Set explicitly, or leave empty for auto-discovery (PATH → common install locations)</div>
                    </div>
                </div>
                <table class="cfg-table">
                    @foreach(['mysqldump','mysql','pg_dump','psql','sqlite3','gzip'] as $bin)
                        <tr>
                            <td>{{ $bin }}</td>
                            <td>
                                @if(!empty($config['binaries'][$bin]))
                                    <code>{{ $config['binaries'][$bin] }}</code>
                                @else
                                    <span class="muted" style="font-size:12px">(auto-discover)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

        </div>

        <div class="footer-note">
            ℹ Edit <code>config/backup-station.php</code> or set the matching <code>BACKUP_STATION_*</code> environment variables in <code>.env</code> to change these settings.
        </div>

    </div>
</div>
</body>
</html>
