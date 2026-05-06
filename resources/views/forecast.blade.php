<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forecast - Backup Station</title>
    @include('backup-station::partials.styles')
    <script>@include('backup-station::partials.theme-js')</script>
</head>
<body>
<div class="layout">
    @include('backup-station::partials.nav')
    <div class="container">

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:18px;">
            <div>
                <h2 style="font-size:22px; letter-spacing:-0.02em;">Retention Forecast</h2>
                <p class="muted" style="margin-top:4px">Backups that the current retention policy will delete in the next {{ $days }} days.</p>
            </div>
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <label class="muted" style="font-size:12px;">Window
                    <select name="days" onchange="this.form.submit()" style="padding:7px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text);">
                        @foreach([1,3,7,14,30,60,90] as $d)
                            <option value="{{ $d }}" @selected($days === $d)>{{ $d }} days</option>
                        @endforeach
                    </select>
                </label>
                <a href="{{ route('backup-station.index') }}" class="btn">← Back</a>
            </form>
        </div>

        <div class="stat-grid" style="margin-bottom:18px;">
            <div class="stat" style="border-left: 3px solid var(--danger-text)">
                <div class="label">Will be deleted</div>
                <div class="value" style="color: var(--danger-text)">{{ count($forecast['entries']) }}</div>
                <div class="sub">in the next {{ $days }} days</div>
            </div>
            <div class="stat">
                <div class="label">Disk freed (est.)</div>
                <div class="value">{{ $service->formatBytes(array_sum(array_map(fn($e) => (int)($e['size'] ?? 0), $forecast['entries']))) }}</div>
            </div>
            @if(!empty($forecast['by_day']))
                <div class="stat" style="grid-column: span 2">
                    <div class="label">By Day</div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;">
                        @foreach($forecast['by_day'] as $day => $n)
                            <span class="badge badge-warning">{{ $day }}: {{ $n }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="card">
            <table class="bk-table">
                <thead>
                <tr>
                    <th>Filename</th>
                    <th>Database</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th>Reason</th>
                </tr>
                </thead>
                <tbody>
                @forelse($forecast['entries'] as $e)
                    @php $reason = $forecast['reasons'][$e['id']] ?? '—'; @endphp
                    <tr>
                        <td>
                            <div class="filename">{{ $e['filename'] ?? '—' }}</div>
                            @if(!empty($e['monthly_keep']))<span class="badge badge-info" style="margin-top:4px">Monthly</span>@endif
                        </td>
                        <td>
                            <div>{{ $e['database'] ?? '—' }}</div>
                            <div class="muted">{{ $e['driver'] ?? '' }}</div>
                        </td>
                        <td>{{ $service->formatBytes((int)($e['size'] ?? 0)) }}</td>
                        <td>
                            <div>{{ \Carbon\Carbon::parse($e['created_at'])->format('Y-m-d H:i') }}</div>
                            <div class="muted">{{ \Carbon\Carbon::parse($e['created_at'])->diffForHumans() }}</div>
                        </td>
                        <td><span class="badge badge-warning">{{ $reason }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:30px; text-align:center; color:var(--success-text)">
                            ✓ Nothing scheduled for deletion in the next {{ $days }} days.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

    </div>
</div>
</body>
</html>
