<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Station</title>
    @include('backup-station::partials.styles')
    <script>@include('backup-station::partials.theme-js')</script>
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

        <div class="stat-grid">
            <div class="stat">
                <div class="label">Total Backups</div>
                <div class="value">{{ $stats['total'] }}</div>
                <div class="sub">{{ $stats['success'] }} success · {{ $stats['failed'] }} failed</div>
            </div>
            <div class="stat" style="border-left: 3px solid var(--primary)">
                <div class="label">Total Size</div>
                <div class="value" style="color: var(--primary)">{{ $service->formatBytes($stats['total_size']) }}</div>
                <div class="sub">on <code>{{ $service->diskName() }}</code></div>
            </div>
            <div class="stat" style="border-left: 3px solid var(--info-text)">
                <div class="label">Database Size</div>
                <div class="value" style="color: var(--info-text)">{{ $service->formatBytes((int)($dbSize['size'] ?? 0)) }}</div>
                <div class="sub">
                    <code>{{ $dbSize['database'] ?? '—' }}</code>
                    <span class="muted">· {{ $dbSize['driver'] ?? '' }}</span>
                </div>
            </div>
            <div class="stat">
                <div class="label">Successful</div>
                <div class="value" style="color: var(--success-text)">{{ $stats['success'] }}</div>
            </div>
            <div class="stat">
                <div class="label">Failed</div>
                <div class="value" style="color: var(--danger-text)">{{ $stats['failed'] }}</div>
            </div>
            <div class="stat">
                <div class="label">Monthly Snapshots</div>
                <div class="value">{{ $stats['monthly'] }}</div>
                <div class="sub">{{ $stats['pinned'] }} marked</div>
            </div>
            <div class="stat">
                <div class="label">Latest</div>
                <div class="value" style="font-size:14px; line-height:1.4">
                    @if($stats['latest'])
                        <div class="filename">{{ $stats['latest']['filename'] }}</div>
                        <div class="muted">{{ \Carbon\Carbon::parse($stats['latest']['created_at'])->diffForHumans() }}</div>
                    @else
                        <span class="muted">No backups yet</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <button class="btn btn-primary" type="button" onclick="openRunDialog()">+ Run Backup Now</button>
                @if(config('backup-station.allow_delete', true))
                    <form method="POST" action="{{ route('backup-station.cleanup') }}" id="cleanup-form" style="display:inline" data-loading="Running cleanup…">
                        @csrf
                        <button class="btn js-cleanup" type="button" title="Apply retention policy now">Cleanup</button>
                    </form>
                @endif

                @if(config('backup-station.allow_import', false))
                    <button class="btn" type="button" onclick="openImport()" title="Upload an existing backup file">⬆ Import Backup</button>
                @endif

                @php $curStatus = $status ?: 'all'; $curPinned = $pinned ?: 'all'; @endphp

                <form method="GET" id="filter-form" style="margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:flex-end;">
                    <input type="hidden" name="status" id="f-status" value="{{ $curStatus }}">
                    <input type="hidden" name="pinned" id="f-pinned" value="{{ $curPinned }}">
                    <input type="text" name="q" value="{{ $search }}" placeholder="Search filename or db…" />
                    <label class="muted" style="font-size:12px;">From
                        <input type="date" name="from" value="{{ $from }}" />
                    </label>
                    <label class="muted" style="font-size:12px;">To
                        <input type="date" name="to" value="{{ $to }}" />
                    </label>
                    <select name="per_page">
                        @foreach(config('backup-station.viewer.per_page_options', [10,25,50,100]) as $opt)
                            <option value="{{ $opt }}" @selected($perPage == $opt)>{{ $opt }} / page</option>
                        @endforeach
                    </select>
                    <button class="btn" type="submit">Filter</button>
                    @if($search || ($status && $status !== 'all') || ($pinned && $pinned !== 'all') || $from || $to)
                        <a class="btn" href="{{ route('backup-station.index') }}" title="Reset filters">✕ Reset</a>
                    @endif
                </form>

                {{-- Full-width second row: Status on the left, Pin on the right --}}
                <div style="width:100%; display:flex; gap:14px; flex-wrap:wrap; justify-content:space-between; align-items:center;">
                    <div class="filter-group">
                        <span class="filter-label">Status</span>
                        <button type="button" class="filter-pill {{ $curStatus === 'all' ? 'active' : '' }}"     data-field="f-status" data-value="all">All</button>
                        <button type="button" class="filter-pill {{ $curStatus === 'success' ? 'active' : '' }}" data-field="f-status" data-value="success">Success</button>
                        <button type="button" class="filter-pill {{ $curStatus === 'failed' ? 'active' : '' }}"  data-field="f-status" data-value="failed">Failed</button>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">Mark</span>
                        <button type="button" class="filter-pill {{ $curPinned === 'all' ? 'active' : '' }}"      data-field="f-pinned" data-value="all">All</button>
                        <button type="button" class="filter-pill {{ $curPinned === 'pinned' ? 'active' : '' }}"   data-field="f-pinned" data-value="pinned">★ Marked</button>
                        <button type="button" class="filter-pill {{ $curPinned === 'unpinned' ? 'active' : '' }}" data-field="f-pinned" data-value="unpinned">☆ Unmarked</button>
                    </div>
                </div>
            </div>

            <table class="bk-table">
                <thead>
                <tr>
                    <th style="width:36px"></th>
                    <th>Filename</th>
                    <th>Database</th>
                    <th>Size</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:right">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($paginator as $b)
                    @php $id = $b['id']; @endphp
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('backup-station.pin') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="id" value="{{ $id }}">
                                <button type="submit" class="pin {{ empty($b['pinned']) ? 'off' : '' }}" title="{{ empty($b['pinned']) ? 'Mark' : 'Unmark' }}" style="background:none;border:none;font-size:18px;cursor:pointer;padding:2px 4px;">★</button>
                            </form>
                        </td>
                        <td>
                            <div class="filename">{{ $b['filename'] ?? '—' }}</div>
                            @if(!empty($b['note']))<div class="muted">{{ $b['note'] }}</div>@endif
                            @if(!empty($b['monthly_keep']))<span class="badge badge-info" style="margin-top:4px">Monthly</span>@endif
                            @if(!empty($b['filename']) && str_ends_with(strtolower($b['filename']), '.zip'))
                                <span class="badge badge-warning" style="margin-top:4px" title="AES-256 password protected">🔒 Encrypted</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $b['database'] ?? '—' }}</div>
                            <div class="muted">{{ $b['driver'] ?? '' }} · {{ $b['connection'] ?? '' }}</div>
                        </td>
                        <td>
                            <div>{{ $service->formatBytes((int)($b['size'] ?? 0)) }}</div>
                            @if(!empty($b['duration_ms']))
                                <div class="muted" title="Time to create this backup">⏱ {{ $service->formatDuration($b['duration_ms']) }}</div>
                            @endif
                        </td>
                        <td>
                            @if(($b['status'] ?? null) === 'success')
                                @if(!($b['_exists'] ?? true))
                                    <span class="badge badge-warning" title="File not found on storage disk">⚠ Missing file</span>
                                @else
                                    <span class="badge badge-success">Success</span>
                                @endif
                            @else
                                <span class="badge badge-danger">Failed</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ \Carbon\Carbon::parse($b['created_at'])->format('Y-m-d H:i') }}</div>
                            <div class="muted">{{ \Carbon\Carbon::parse($b['created_at'])->diffForHumans() }}</div>
                            @if(!empty($b['last_restored_at']))
                                @php
                                    $lastStatus = $b['last_restore_status'] ?? 'success';
                                    $color = $lastStatus === 'failed' ? 'var(--danger-text)' : 'var(--info-text)';
                                    $lastUser = collect((array)($b['restores'] ?? []))->last()['user_name'] ?? null;
                                @endphp
                                <div class="muted" style="margin-top:4px;color:{{ $color }}" title="Last restore">
                                    ↺ Restored {{ \Carbon\Carbon::parse($b['last_restored_at'])->diffForHumans() }}
                                    @if(!empty($b['last_restore_ms']))
                                        <span style="opacity:0.8">({{ $service->formatDuration($b['last_restore_ms']) }})</span>
                                    @endif
                                    @if($lastUser)
                                        <span style="opacity:0.8">— by {{ $lastUser }}</span>
                                    @endif
                                </div>
                            @endif
                            @if(!empty($b['imported']) && !empty($b['import']['user_name']))
                                <div class="muted" style="margin-top:2px;font-size:11px;opacity:0.85">
                                    ⬆ Imported by {{ $b['import']['user_name'] }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions">
                                @php $exists = $b['_exists'] ?? true; @endphp
                                @if(($b['status'] ?? null) === 'success' && $exists)
                                    @if(config('backup-station.download_password'))
                                        <form method="POST" action="{{ route('backup-station.download', $id) }}" style="display:inline" id="dl-form-{{ $id }}">
                                            @csrf
                                            <input type="hidden" name="download_password" value="" id="dl-pw-{{ $id }}">
                                            <button type="button" class="btn btn-sm btn-success js-download"
                                                    data-id="{{ $id }}">↓ Download</button>
                                        </form>
                                    @else
                                        <a class="btn btn-sm btn-success" href="{{ route('backup-station.download', $id) }}">↓ Download</a>
                                    @endif
                                    @if(config('backup-station.allow_restore', false))
                                        <form method="POST" action="{{ route('backup-station.restore') }}" style="display:inline" id="restore-form-{{ $id }}" data-loading="Restoring backup…">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $id }}">
                                            <input type="hidden" name="restore_password" value="" id="restore-pw-{{ $id }}">
                                            <button type="button" class="btn btn-sm js-restore"
                                                    data-id="{{ $id }}"
                                                    data-filename="{{ $b['filename'] ?? '' }}"
                                                    data-database="{{ $b['database'] ?? '' }}"
                                                    data-needs-pw="{{ config('backup-station.restore_password') ? '1' : '0' }}">↺ Restore</button>
                                        </form>
                                    @endif
                                    <button type="button" class="btn btn-sm js-rename"
                                            data-id="{{ $id }}"
                                            data-filename="{{ $b['filename'] ?? '' }}">Rename</button>
                                @elseif(($b['status'] ?? null) === 'success' && !$exists)
                                    <span class="muted" style="font-size:12px">File missing</span>
                                @elseif(!empty($b['error']))
                                    <button type="button" class="btn btn-sm btn-danger js-view-error"
                                            data-error="{{ $b['error'] }}">View error</button>
                                @endif

                                @if(config('backup-station.allow_delete', true))
                                    <form method="POST" action="{{ route('backup-station.delete') }}" style="display:inline" id="del-form-{{ $id }}">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $id }}">
                                        <button type="button" class="btn btn-sm btn-danger js-delete"
                                                data-id="{{ $id }}"
                                                data-filename="{{ $b['filename'] ?? '—' }}"
                                                data-disk="{{ $service->diskName() }}">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:30px; text-align:center; color:var(--text-muted)">No backups yet. Click <strong>Run Backup Now</strong> to create one.</td></tr>
                @endforelse
                </tbody>
            </table>

            @if($paginator->hasPages())
                <div class="pagination-bar">
                    <div>
                        Showing {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} of {{ number_format($paginator->total()) }}
                    </div>
                    <div class="page-links">
                        @if($paginator->onFirstPage())
                            <span class="disabled">{!! '&#8592;' !!}</span>
                        @else
                            <a href="{{ $paginator->previousPageUrl() }}">{!! '&#8592;' !!}</a>
                        @endif

                        @foreach($paginator->getUrlRange(max(1, $paginator->currentPage()-2), min($paginator->lastPage(), $paginator->currentPage()+2)) as $p => $url)
                            @if($p == $paginator->currentPage())
                                <span class="current">{{ $p }}</span>
                            @else
                                <a href="{{ $url }}">{{ $p }}</a>
                            @endif
                        @endforeach

                        @if($paginator->hasMorePages())
                            <a href="{{ $paginator->nextPageUrl() }}">{!! '&#8594;' !!}</a>
                        @else
                            <span class="disabled">{!! '&#8594;' !!}</span>
                        @endif
                    </div>
                </div>
            @else
                <div class="pagination-bar">
                    <span>{{ number_format($paginator->total()) }} entries</span>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="bs-loading" id="bs-loading">
    <div class="box">
        <div class="bs-spinner"></div>
        <div class="title" id="bs-loading-title">Working…</div>
        <div class="sub" id="bs-loading-sub">Please wait, this can take a while for large databases.</div>
    </div>
</div>

<div class="modal-backdrop" id="run-modal">
    <form method="POST" action="{{ route('backup-station.run') }}" class="modal" style="max-width:720px;max-height:88vh;display:flex;flex-direction:column;" data-loading="Creating backup…">
        @csrf
        <h3>Run Backup</h3>
        <p class="muted" style="margin:6px 0 14px">For each table, choose whether to dump its <em>structure</em> (CREATE TABLE) and/or its <em>data</em> (rows).</p>

        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <input type="text" id="run-table-search" placeholder="Filter tables…" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:12px;">
        </div>

        <div id="run-tables-wrapper" style="flex:1;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);min-height:200px;max-height:380px">
            <div id="run-tables-list">
                <div class="muted" style="text-align:center;padding:30px">Loading tables…</div>
            </div>
        </div>
        <div class="muted" style="font-size:11px;margin-top:6px">
            <span id="run-struct-count">0</span> structure · <span id="run-data-count">0</span> data
        </div>

        <div style="margin-top:12px">
            <label class="muted" style="font-size:12px">Note (optional)</label>
            <input type="text" name="note" placeholder="e.g. Pre-deploy" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:13px">
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" onclick="closeRunDialog()">Cancel</button>
            <button type="submit" class="btn btn-primary">+ Run Backup</button>
        </div>
    </form>
</div>

<style>
    .run-tbl-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .run-tbl-table th { position:sticky; top:0; background:var(--bg-card); padding:8px 12px; text-align:left; border-bottom:1px solid var(--border); font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:600; z-index:1; }
    .run-tbl-table th input { vertical-align:middle; }
    .run-tbl-table td { padding:7px 12px; border-bottom:1px solid var(--border-light); }
    .run-tbl-table tr:hover td { background:var(--hover); }
    .run-tbl-table .col-cb { width:30px; text-align:center; }
    .run-tbl-table .col-name { font-family:var(--font-mono); }
    .run-tbl-table .rows-tag { font-size:11px; color:var(--text-light); margin-left:6px; }
    .run-tbl-table th.run-sort { cursor:pointer; user-select:none; }
    .run-tbl-table th.run-sort:hover { color:var(--primary); }
</style>

<div class="modal-backdrop" id="confirm-modal">
    <div class="modal" style="max-width:440px">
        <h3 id="confirm-title">Are you sure?</h3>
        <div id="confirm-message" class="muted" style="margin:8px 0 4px; line-height:1.6;"></div>
        <div class="modal-actions">
            <button type="button" class="btn" id="confirm-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirm-ok">Confirm</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="error-modal">
    <div class="modal" style="max-width:640px">
        <h3 style="color:var(--danger-text)">Backup error</h3>
        <pre id="error-text" style="background:var(--bg);padding:12px;border-radius:var(--radius-sm);font-family:var(--font-mono);font-size:12px;color:var(--danger-text);white-space:pre-wrap;word-break:break-word;max-height:380px;overflow:auto;border:1px solid var(--danger-border);margin-top:6px;"></pre>
        <div class="modal-actions">
            <button type="button" class="btn" onclick="document.getElementById('error-modal').classList.remove('open')">Close</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="import-modal">
    <form method="POST" action="{{ route('backup-station.import') }}" enctype="multipart/form-data" class="modal" data-loading="Uploading backup…">
        @csrf
        <h3>Import Backup</h3>
        <p class="muted" style="margin-bottom:14px">Upload an existing backup file (<code>.sql</code>, <code>.sql.gz</code>, <code>.gz</code>, or <code>.zip</code>).</p>
        <label for="import-file">Backup file</label>
        <input type="file" name="file" id="import-file" accept=".sql,.gz,.zip,.sql.gz" required>
        <div style="margin-top:12px">
            <label for="import-note">Note (optional)</label>
            <input type="text" name="note" id="import-note" placeholder="e.g. Pre-migration snapshot">
        </div>
        @if(config('backup-station.import_password'))
            <div style="margin-top:12px">
                <label for="import-pw">Confirmation password</label>
                <input type="password" name="import_password" id="import-pw" autocomplete="new-password" required>
            </div>
        @endif
        <div class="modal-actions">
            <button type="button" class="btn" onclick="closeImport()">Cancel</button>
            <button type="submit" class="btn btn-primary">Upload</button>
        </div>
    </form>
</div>

<div class="modal-backdrop" id="rename-modal">
    <form method="POST" action="{{ route('backup-station.rename') }}" class="modal" onsubmit="return doRename(event);">
        @csrf
        <h3>Rename Backup</h3>
        <input type="hidden" name="id" id="rename-id">
        <label for="rename-name">New filename (extension is preserved)</label>
        <input type="text" name="name" id="rename-name" required autofocus>
        <div class="modal-actions">
            <button type="button" class="btn" onclick="closeRename()">Cancel</button>
            <button type="submit" class="btn btn-primary">Rename</button>
        </div>
    </form>
</div>

<script>
    function showError(text) {
        document.getElementById('error-text').textContent = text || '(no details)';
        document.getElementById('error-modal').classList.add('open');
    }
    document.getElementById('error-modal').addEventListener('click', (e) => {
        if (e.target.id === 'error-modal') e.currentTarget.classList.remove('open');
    });

    function confirmDialog({ title, message, confirm = 'Confirm', danger = false, onConfirm }) {
        const modal = document.getElementById('confirm-modal');
        document.getElementById('confirm-title').textContent = title || 'Are you sure?';
        document.getElementById('confirm-message').innerHTML = message || '';
        const ok = document.getElementById('confirm-ok');
        ok.textContent = confirm;
        ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
        modal.classList.add('open');

        const cleanup = () => { modal.classList.remove('open'); ok.onclick = null; };
        ok.onclick = () => { cleanup(); if (onConfirm) onConfirm(); };
        document.getElementById('confirm-cancel').onclick = cleanup;
        modal.onclick = (e) => { if (e.target === modal) cleanup(); };
    }

    const importModal = document.getElementById('import-modal');
    function openImport() { importModal && importModal.classList.add('open'); }
    function closeImport() { importModal && importModal.classList.remove('open'); }
    if (importModal) importModal.addEventListener('click', (e) => {
        if (e.target.id === 'import-modal') closeImport();
    });

    function openRename(id, current) {
        document.getElementById('rename-id').value = id;
        const base = (current || '').replace(/\.sql\.gz$/i, '').replace(/\.sql$/i, '');
        document.getElementById('rename-name').value = base;
        document.getElementById('rename-modal').classList.add('open');
        setTimeout(() => document.getElementById('rename-name').focus(), 50);
    }
    function closeRename() { document.getElementById('rename-modal').classList.remove('open'); }
    function doRename(e) {
        if (!document.getElementById('rename-name').value.trim()) { e.preventDefault(); return false; }
        return true;
    }
    document.getElementById('rename-modal').addEventListener('click', (e) => {
        if (e.target.id === 'rename-modal') closeRename();
    });

    // Bind row action buttons via data-* attributes (avoids inline onclick escaping issues).
    document.querySelectorAll('.js-rename').forEach(btn => {
        btn.addEventListener('click', () => openRename(btn.dataset.id, btn.dataset.filename));
    });

    document.querySelectorAll('.js-view-error').forEach(btn => {
        btn.addEventListener('click', () => showError(btn.dataset.error));
    });

    /* ---------- Run Backup dialog with table picker ---------- */
    const runModal = document.getElementById('run-modal');
    let runTablesLoaded = false;

    function openRunDialog() {
        runModal.classList.add('open');
        if (!runTablesLoaded) loadRunTables();
    }
    function closeRunDialog() { runModal.classList.remove('open'); }
    runModal.addEventListener('click', (e) => {
        if (e.target.id === 'run-modal') closeRunDialog();
    });

    let runTablesData = [];                              // raw rows from API
    let runSort = { key: 'name', dir: 'asc' };           // active sort state

    function loadRunTables() {
        const list = document.getElementById('run-tables-list');
        fetch('{{ route('backup-station.tables') }}', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    list.innerHTML = '<div style="color:var(--danger-text);padding:10px">' + escapeHtml(data.error) + '</div>';
                    return;
                }
                runTablesData = (data.tables || []).map(t => ({
                    name: String(t.name),
                    rows: Number(t.rows || 0),
                    size: Number(t.size || 0),
                }));
                if (runTablesData.length === 0) {
                    list.innerHTML = '<div class="muted" style="text-align:center;padding:20px">No tables found.</div>';
                    return;
                }
                renderRunTables();
                runTablesLoaded = true;
            })
            .catch(err => {
                list.innerHTML = '<div style="color:var(--danger-text);padding:10px">Failed to load tables: ' + escapeHtml(err.message) + '</div>';
            });
    }

    /**
     * Re-render the table preserving each row's current checkbox state
     * (keyed by table name) so sorting / filtering doesn't reset choices.
     */
    function renderRunTables() {
        const prev = collectRunSelection();
        const rows = sortRunTables([...runTablesData], runSort.key, runSort.dir);

        const arrow = (k) => runSort.key === k ? (runSort.dir === 'asc' ? ' ▲' : ' ▼') : '';

        let html = '<table class="run-tbl-table">'
            + '<thead><tr>'
            + '<th class="col-cb"><input type="checkbox" id="run-toggle-all" checked title="Toggle all"></th>'
            + '<th class="run-sort" data-key="name">Table' + arrow('name') + '</th>'
            + '<th class="run-sort" data-key="rows" style="text-align:right">Rows' + arrow('rows') + '</th>'
            + '<th class="run-sort" data-key="size" style="text-align:right">Size' + arrow('size') + '</th>'
            + '<th class="col-cb">Structure <input type="checkbox" id="run-toggle-structure" checked title="Toggle all structure"></th>'
            + '<th class="col-cb">Data <input type="checkbox" id="run-toggle-data" checked title="Toggle all data"></th>'
            + '</tr></thead><tbody>';

        for (const t of rows) {
            const safe = escapeHtml(t.name);
            const sChecked = (prev[t.name]?.s ?? true) ? 'checked' : '';
            const dChecked = (prev[t.name]?.d ?? true) ? 'checked' : '';
            const rChecked = (sChecked || dChecked) ? 'checked' : '';
            html += '<tr class="run-table-row" data-name="' + safe + '">'
                + '<td class="col-cb"><input type="checkbox" class="run-row-toggle" ' + rChecked + '></td>'
                + '<td class="col-name">' + safe + '</td>'
                + '<td style="text-align:right;color:var(--text-muted)">' + (t.rows ? t.rows.toLocaleString() : '—') + '</td>'
                + '<td style="text-align:right;color:var(--text-muted)">' + (t.size ? formatBytes(t.size) : '—') + '</td>'
                + '<td class="col-cb"><input type="checkbox" class="run-cb-structure" name="tables_structure[]" value="' + safe + '" ' + sChecked + '></td>'
                + '<td class="col-cb"><input type="checkbox" class="run-cb-data" name="tables_data[]" value="' + safe + '" ' + dChecked + '></td>'
                + '</tr>';
        }
        html += '</tbody></table>';

        document.getElementById('run-tables-list').innerHTML = html;

        bindRunTableEvents();
        bindRunSortHeaders();
        applyRunFilter();
        updateRunCounts();
    }

    function collectRunSelection() {
        const map = {};
        document.querySelectorAll('.run-table-row').forEach(tr => {
            const name = tr.dataset.name;
            map[name] = {
                s: tr.querySelector('.run-cb-structure')?.checked,
                d: tr.querySelector('.run-cb-data')?.checked,
            };
        });
        return map;
    }

    function sortRunTables(rows, key, dir) {
        const mul = dir === 'asc' ? 1 : -1;
        return rows.sort((a, b) => {
            const av = a[key], bv = b[key];
            if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * mul;
            return String(av).localeCompare(String(bv)) * mul;
        });
    }

    function bindRunSortHeaders() {
        document.querySelectorAll('.run-sort').forEach(th => {
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.addEventListener('click', () => {
                const key = th.dataset.key;
                if (runSort.key === key) {
                    runSort.dir = runSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    runSort.key = key;
                    runSort.dir = key === 'name' ? 'asc' : 'desc';
                }
                renderRunTables();
            });
        });
    }

    function applyRunFilter() {
        const q = (document.getElementById('run-table-search').value || '').toLowerCase();
        document.querySelectorAll('.run-table-row').forEach(row => {
            const name = (row.dataset.name || '').toLowerCase();
            row.style.display = name.includes(q) ? '' : 'none';
        });
    }

    /** JS-side bytes formatter (mirrors PHP formatBytes). */
    function formatBytes(b) {
        if (!b || b <= 0) return '0 B';
        const u = ['B','KB','MB','GB','TB'];
        const i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), u.length - 1);
        return (Math.round(b / Math.pow(1024, i) * 100) / 100) + ' ' + u[i];
    }

    function bindRunTableEvents() {
        // Header — select-all toggles every checkbox.
        const toggleAll = document.getElementById('run-toggle-all');
        const toggleS = document.getElementById('run-toggle-structure');
        const toggleD = document.getElementById('run-toggle-data');

        const visibleRows = () => Array.from(document.querySelectorAll('.run-table-row'))
            .filter(r => r.style.display !== 'none');

        toggleAll.addEventListener('change', () => {
            const c = toggleAll.checked;
            visibleRows().forEach(r => {
                r.querySelector('.run-row-toggle').checked = c;
                r.querySelector('.run-cb-structure').checked = c;
                r.querySelector('.run-cb-data').checked = c;
            });
            toggleS.checked = c; toggleD.checked = c;
            updateRunCounts();
        });

        toggleS.addEventListener('change', () => {
            const c = toggleS.checked;
            visibleRows().forEach(r => r.querySelector('.run-cb-structure').checked = c);
            updateRunCounts();
        });

        toggleD.addEventListener('change', () => {
            const c = toggleD.checked;
            visibleRows().forEach(r => r.querySelector('.run-cb-data').checked = c);
            updateRunCounts();
        });

        // Per-row toggles — flips both structure + data for that row.
        document.querySelectorAll('.run-row-toggle').forEach(cb => {
            cb.addEventListener('change', () => {
                const tr = cb.closest('tr');
                tr.querySelector('.run-cb-structure').checked = cb.checked;
                tr.querySelector('.run-cb-data').checked = cb.checked;
                updateRunCounts();
            });
        });

        document.querySelectorAll('.run-cb-structure, .run-cb-data').forEach(cb => {
            cb.addEventListener('change', () => {
                const tr = cb.closest('tr');
                const s = tr.querySelector('.run-cb-structure').checked;
                const d = tr.querySelector('.run-cb-data').checked;
                tr.querySelector('.run-row-toggle').checked = s || d;
                updateRunCounts();
            });
        });
    }

    function updateRunCounts() {
        document.getElementById('run-struct-count').textContent =
            document.querySelectorAll('.run-cb-structure:checked').length;
        document.getElementById('run-data-count').textContent =
            document.querySelectorAll('.run-cb-data:checked').length;
    }

    document.getElementById('run-table-search').addEventListener('input', applyRunFilter);

    document.querySelectorAll('.js-download').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const pw = window.prompt('Enter the download password:');
            if (pw === null || pw === '') return;
            document.getElementById('dl-pw-' + id).value = pw;
            document.getElementById('dl-form-' + id).submit();
        });
    });

    document.querySelectorAll('.js-restore').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const filename = btn.dataset.filename || '—';
            const database = btn.dataset.database || '—';
            const needsPw = btn.dataset.needsPw === '1';

            confirmDialog({
                title: '⚠ Restore Backup?',
                message: 'This will <strong>OVERWRITE</strong> the database <code>' + escapeHtml(database)
                    + '</code> with the contents of <code>' + escapeHtml(filename)
                    + '</code>. All current data may be replaced. This cannot be undone.',
                confirm: needsPw ? 'Continue' : 'Yes, restore',
                danger: true,
                onConfirm: () => {
                    if (needsPw) {
                        const pw = window.prompt('Enter the restore confirmation password:');
                        if (pw === null || pw === '') return;
                        document.getElementById('restore-pw-' + id).value = pw;
                    }
                    const form = document.getElementById('restore-form-' + id);
                    showLoading(form.dataset.loading || 'Restoring backup…');
                    form.submit();
                }
            });
        });
    });

    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const filename = btn.dataset.filename || '—';
            const disk = btn.dataset.disk || '';
            confirmDialog({
                title: 'Delete Backup?',
                message: 'The file <code>' + escapeHtml(filename) + '</code> will be permanently deleted'
                    + (disk ? ' from <code>' + escapeHtml(disk) + '</code>' : '')
                    + '. This cannot be undone.',
                confirm: 'Delete',
                danger: true,
                onConfirm: () => {
                    const f = document.getElementById('del-form-' + id);
                    showLoading('Deleting backup…');
                    f.submit();
                }
            });
        });
    });

    document.querySelectorAll('.filter-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            const field = document.getElementById(btn.dataset.field);
            if (field) field.value = btn.dataset.value;
            document.getElementById('filter-form').submit();
        });
    });

    document.querySelectorAll('.js-cleanup').forEach(btn => {
        btn.addEventListener('click', () => {
            confirmDialog({
                title: 'Run Cleanup?',
                message: 'This will permanently delete backups that exceed the retention policy (max copies, age limit, monthly cap). Pinned and monthly snapshots are protected.',
                confirm: 'Run Cleanup',
                danger: true,
                onConfirm: () => {
                    const f = document.getElementById('cleanup-form');
                    showLoading(f.dataset.loading || 'Running cleanup…');
                    f.submit();
                }
            });
        });
    });

    const bsLoading = document.getElementById('bs-loading');
    function showLoading(title) {
        document.getElementById('bs-loading-title').textContent = title || 'Working…';
        bsLoading.classList.add('open');
    }
    document.querySelectorAll('form[data-loading]').forEach(form => {
        form.addEventListener('submit', () => showLoading(form.dataset.loading));
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
</script>
</body>
</html>
