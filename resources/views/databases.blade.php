<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Databases — Backup Station</title>
    @include('backup-station::partials.styles')
    <script>@include('backup-station::partials.theme-js')</script>
    <style>
        .db-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .db-table th { padding: 9px 14px; text-align: left; border-bottom: 1px solid var(--border); background: var(--bg); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; white-space: nowrap; }
        .db-table th.sortable { cursor: pointer; user-select: none; }
        .db-table th.sortable:hover { color: var(--primary); }
        .db-table td { padding: 9px 14px; border-bottom: 1px solid var(--border-light); vertical-align: middle; }
        .db-table tbody tr:hover td { background: var(--hover); }
        .db-table tfoot td { background: var(--bg); border-top: 1px solid var(--border); border-bottom: none; font-weight: 700; }
        .db-table td code { font-family: var(--font-mono); font-size: 12px; background: var(--bg); padding: 2px 8px; border-radius: 4px; color: var(--text); }
        .db-table .num { text-align: right; font-family: var(--font-mono); }
        .badge-pill { display:inline-flex; padding:2px 9px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
        .badge-ok { background: var(--success-bg); color: var(--success-text); }
        .badge-down { background: var(--danger-bg); color: var(--danger-text); }
    </style>
</head>
<body>
<div class="layout">
    @include('backup-station::partials.nav')

    <div class="container">
        @if(session('flash'))
            <div class="flash flash-success">{{ session('flash') }}</div>
        @endif
        @if(session('flash_error'))
            <div class="flash flash-error">{{ session('flash_error') }}</div>
        @endif

        @php
            $bsMulti = count($connectionsInfo) > 1;
            $bsUiScope = ($scopeSource ?? 'ui') === 'ui';
        @endphp
        @if($bsMulti && !$bsUiScope)
            <div class="flash" style="background:var(--info-bg); border:1px solid var(--info-border); color:var(--info-text);">
                ⚙️ Auto-backup scope is managed from <code>config/backup-station.php</code> (<code>scope.source=config</code>) — the toggles below are disabled; edit the <code>scope.only</code> / <code>scope.exclude</code> arrays instead.
            </div>
        @endif
        @if($bsMulti && ($scope['mode'] ?? 'all') === 'only' && !empty($scope['only']))
            <div class="flash" style="background:var(--warning-bg); border:1px solid var(--warning-border); color:var(--warning-text); display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span>⚠️ Automatic (scheduled) backups are restricted to:
                    <strong>{{ implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $scope['only'])) }}</strong>
                    — every other database is skipped on schedule. Manual runs are not affected.</span>
                @if($bsUiScope)
                    <form method="POST" action="{{ route('backup-station.databases.restrict') }}" style="margin-left:auto">
                        @csrf
                        <button class="btn btn-sm" type="submit">✕ Clear restriction</button>
                    </form>
                @endif
            </div>
        @elseif($bsMulti && !empty($scope['exclude']))
            <div class="flash" style="background:var(--info-bg); border:1px solid var(--info-border); color:var(--info-text);">
                ℹ️ {{ count($scope['exclude']) }} database(s) excluded from automatic (scheduled) backups:
                <strong>{{ implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $scope['exclude'])) }}</strong>
            </div>
        @endif

        <div class="card">
            <div class="toolbar">
                <input type="text" id="db-search" placeholder="Search database or name…" style="min-width:220px" />
                <div class="filter-group">
                    <span class="filter-label">Status</span>
                    <button type="button" class="filter-pill active" data-status="all">All</button>
                    <button type="button" class="filter-pill" data-status="ok">Reachable</button>
                    <button type="button" class="filter-pill" data-status="down">Unreachable</button>
                    <button type="button" class="filter-pill" data-status="never">Never backed up</button>
                </div>
                @if($bsMulti)
                    <div class="filter-group">
                        <span class="filter-label">Auto Backup</span>
                        <button type="button" class="filter-pill active" data-abf="all">All</button>
                        <button type="button" class="filter-pill" data-abf="yes">Will back up</button>
                        <button type="button" class="filter-pill" data-abf="no">Skipped</button>
                    </div>
                @endif
                <span class="muted" style="margin-left:auto; font-size:12px" id="db-count"></span>
            </div>

            <table class="db-table">
                <thead>
                <tr>
                    <th class="sortable" data-key="label" data-type="str">Database</th>
                    <th class="sortable" data-key="db" data-type="str">Connection</th>
                    <th>Driver</th>
                    <th class="sortable num" data-key="size" data-type="num">DB Size</th>
                    <th class="sortable num" data-key="tables" data-type="num">Tables</th>
                    <th class="sortable" data-key="last" data-type="num">Last Successful Backup</th>
                    <th>Status</th>
                    @if($bsMulti)
                        <th>Auto Backup</th>
                    @endif
                    <th></th>
                </tr>
                </thead>
                <tbody id="db-rows">
                @php
                    $bsSizeTotal = 0;
                    $bsTablesTotal = 0;
                    $bsUnreachable = 0;
                    $bsNeverBacked = 0;
                @endphp
                @foreach($connectionsInfo as $ci)
                    @php
                        $bsSizeTotal += $ci['ok'] ? $ci['size'] : 0;
                        $bsTablesTotal += $ci['ok'] ? (int) ($ci['tables'] ?? 0) : 0;
                        $bsUnreachable += $ci['ok'] ? 0 : 1;
                        if (!$ci['last_backup']) $bsNeverBacked++;
                        $bsLastTs = $ci['last_backup'] ? \Carbon\Carbon::parse($ci['last_backup']['created_at'])->timestamp : 0;
                    @endphp
                    <tr data-label="{{ mb_strtolower($ci['label']) }}"
                        data-db="{{ mb_strtolower($ci['database'] ?? $ci['connection']) }}"
                        data-size="{{ $ci['ok'] ? $ci['size'] : -1 }}"
                        data-tables="{{ $ci['ok'] ? (int) ($ci['tables'] ?? 0) : -1 }}"
                        data-last="{{ $bsLastTs }}"
                        data-status="{{ $ci['ok'] ? 'ok' : 'down' }}"
                        data-never="{{ $ci['last_backup'] ? 0 : 1 }}"
                        data-auto="{{ $ci['in_backup'] ? 1 : 0 }}">
                        <td style="color:var(--text); font-weight:600">{{ $ci['label'] }}</td>
                        <td><code>{{ $ci['database'] ?? $ci['connection'] }}</code></td>
                        <td class="muted">{{ $ci['driver'] ?? '—' }}</td>
                        <td class="num">{{ $ci['ok'] ? $service->formatBytes($ci['size']) : '—' }}</td>
                        <td class="num">{{ $ci['ok'] && $ci['tables'] !== null ? number_format($ci['tables']) : '—' }}</td>
                        <td>
                            @if($ci['last_backup'])
                                {{ \Carbon\Carbon::parse($ci['last_backup']['created_at'])->locale('en')->diffForHumans() }}
                                <span class="muted">· {{ $service->formatBytes((int) ($ci['last_backup']['size'] ?? 0)) }}</span>
                            @else
                                <span style="color:var(--warning-text)">never</span>
                            @endif
                        </td>
                        <td>
                            @if($ci['ok'])
                                <span class="badge-pill badge-ok">Reachable</span>
                            @else
                                <span class="badge-pill badge-down">Unreachable</span>
                            @endif
                        </td>
                        @if($bsMulti)
                            <td style="white-space:nowrap">
                                @if($ci['in_backup'])
                                    <span class="badge-pill badge-ok" title="Covered by automatic (scheduled) backups">Will back up</span>
                                @else
                                    <span class="badge-pill" style="background:var(--bg); color:var(--text-muted); border:1px solid var(--border)" title="Skipped by automatic (scheduled) backups — manual backups still possible">Skipped</span>
                                @endif
                                @if($bsUiScope)
                                    @if(($scope['mode'] ?? 'all') === 'all')
                                        <form method="POST" action="{{ route('backup-station.databases.toggle') }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="connection" value="{{ $ci['connection'] }}">
                                            <button class="btn btn-sm" type="submit" title="{{ $ci['excluded'] ? 'Include this database in automatic backups' : 'Exclude this database from automatic backups' }}">
                                                {{ $ci['excluded'] ? '+ Include' : '− Exclude' }}
                                            </button>
                                        </form>
                                    @endif
                                    @if(!(($scope['mode'] ?? 'all') === 'only' && in_array($ci['connection'], $scope['only'] ?? [], true)))
                                        <form method="POST" action="{{ route('backup-station.databases.restrict') }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="connection" value="{{ $ci['connection'] }}">
                                            <button class="btn btn-sm" type="submit" title="Automatic backups will cover ONLY this database">⦿ Only this</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        @endif
                        <td style="text-align:right">
                            @if($ci['ok'])
                                <a class="btn btn-sm" href="{{ route('backup-station.index', ['run' => $ci['connection']]) }}" title="Open the Run Backup dialog for this database">▶ Backup now</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td style="color:var(--text)">Total — {{ count($connectionsInfo) }} databases</td>
                    <td colspan="2" class="muted" style="font-weight:500">
                        @if($bsUnreachable) <span style="color:var(--danger-text)">{{ $bsUnreachable }} unreachable</span> @endif
                        @if($bsNeverBacked) <span style="color:var(--warning-text)">· {{ $bsNeverBacked }} never backed up</span> @endif
                    </td>
                    <td class="num">{{ $service->formatBytes($bsSizeTotal) }}</td>
                    <td class="num">{{ number_format($bsTablesTotal) }}</td>
                    <td colspan="{{ $bsMulti ? 4 : 3 }}"></td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
    const tbody = document.getElementById('db-rows');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const searchInput = document.getElementById('db-search');
    const countEl = document.getElementById('db-count');
    let statusFilter = 'all';
    let autoFilter = 'all';
    let sort = { key: null, dir: 'desc' };

    function applyFilters() {
        const q = searchInput.value.trim().toLowerCase();
        let visible = 0;
        rows.forEach(tr => {
            let show = true;
            if (q && !(tr.dataset.label + ' ' + tr.dataset.db).includes(q)) show = false;
            if (statusFilter === 'ok' && tr.dataset.status !== 'ok') show = false;
            if (statusFilter === 'down' && tr.dataset.status !== 'down') show = false;
            if (statusFilter === 'never' && tr.dataset.never !== '1') show = false;
            if (autoFilter === 'yes' && tr.dataset.auto !== '1') show = false;
            if (autoFilter === 'no' && tr.dataset.auto !== '0') show = false;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        countEl.textContent = 'Showing ' + visible + ' of ' + rows.length;
    }

    function applySort(key, type) {
        if (sort.key === key) {
            sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            // Numbers feel natural biggest-first; text A→Z.
            sort = { key, dir: type === 'num' ? 'desc' : 'asc' };
        }
        const mul = sort.dir === 'asc' ? 1 : -1;
        const sorted = [...rows].sort((a, b) => {
            if (type === 'num') return (Number(a.dataset[key]) - Number(b.dataset[key])) * mul;
            return String(a.dataset[key]).localeCompare(String(b.dataset[key])) * mul;
        });
        sorted.forEach(tr => tbody.appendChild(tr));

        document.querySelectorAll('.db-table th.sortable').forEach(th => {
            const base = th.textContent.replace(/ [▲▼]$/, '');
            th.textContent = base + (th.dataset.key === sort.key ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : '');
        });
    }

    searchInput.addEventListener('input', applyFilters);

    document.querySelectorAll('.filter-pill[data-status]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-pill[data-status]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            statusFilter = btn.dataset.status;
            applyFilters();
        });
    });

    document.querySelectorAll('.filter-pill[data-abf]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-pill[data-abf]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            autoFilter = btn.dataset.abf;
            applyFilters();
        });
    });

    document.querySelectorAll('.db-table th.sortable').forEach(th => {
        th.addEventListener('click', () => applySort(th.dataset.key, th.dataset.type));
    });

    applyFilters();
</script>
</body>
</html>
