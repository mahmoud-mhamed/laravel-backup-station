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

        @php
            $bsRate = $stats['success_rate'] ?? null;
            $bsRateColor = $bsRate === null ? 'var(--text-muted)'
                : ($bsRate >= 90 ? 'var(--success-text)' : ($bsRate >= 70 ? 'var(--warning-text)' : 'var(--danger-text)'));
            $bsTargets = count($connectionLabels ?? []);
        @endphp
        <div class="stat-grid">
            <div class="stat">
                <div class="label">Total Backups</div>
                <div class="value">{{ $stats['backups_total'] ?? $stats['total'] }}</div>
                <div class="sub">
                    <span style="color:var(--success-text)">✓ {{ $stats['backups_success'] ?? $stats['success'] }} success</span>
                    <span style="color:var(--danger-text)">· ✗ {{ $stats['backups_failed'] ?? $stats['failed'] }} failed</span>
                </div>
                <div class="sub">{{ $stats['monthly'] }} monthly · {{ $stats['pinned'] }} pinned</div>
            </div>
            <div class="stat" style="border-left: 3px solid {{ $bsRateColor }}">
                <div class="label">Success Rate</div>
                <div class="value" style="color: {{ $bsRateColor }}">{{ $bsRate === null ? '—' : $bsRate . '%' }}</div>
                <div class="sub">avg duration {{ $service->formatDuration($stats['avg_duration_ms'] ?? null) }}</div>
            </div>
            <div class="stat" style="border-left: 3px solid var(--primary)">
                <div class="label">Total Size</div>
                <div class="value" style="color: var(--primary)">{{ $service->formatBytes($stats['total_size']) }}</div>
                <div class="sub">on <code>{{ $service->diskName() }}</code> · avg {{ $service->formatBytes((int) ($stats['avg_size'] ?? 0)) }}/backup</div>
            </div>
            <div class="stat" style="border-left: 3px solid var(--info-text)">
                @if(!empty($dbSizeSummary))
                    <div class="label">Databases Size</div>
                    <div class="value" style="color: var(--info-text)">{{ $service->formatBytes((int) $dbSizeSummary['size']) }}</div>
                    <div class="sub">total of {{ $dbSizeSummary['count'] }} databases</div>
                    @if($dbSizeSummary['missing'] > 0)
                        <div class="sub" style="color:var(--danger-text)">{{ $dbSizeSummary['missing'] }} unreachable</div>
                    @endif
                @else
                    <div class="label">Database Size</div>
                    <div class="value" style="color: var(--info-text)">{{ $service->formatBytes((int)($dbSize['size'] ?? 0)) }}</div>
                    <div class="sub">
                        <code>{{ $dbSize['database'] ?? '—' }}</code>
                        <span class="muted">· {{ $dbSize['driver'] ?? '' }}</span>
                    </div>
                @endif
            </div>
            <div class="stat" style="border-left: 3px solid var(--success-text)">
                <div class="label">Latest Backup</div>
                <div class="value" style="font-size:13px; line-height:1.6">
                    @if($stats['latest'])
                        <div class="filename stat-filename" title="{{ $stats['latest']['filename'] }}">{{ $stats['latest']['filename'] }}</div>
                        <div class="muted" style="font-size:12px">{{ \Carbon\Carbon::parse($stats['latest']['created_at'])->locale('en')->diffForHumans() }} · {{ \Carbon\Carbon::parse($stats['latest']['created_at'])->format('Y-m-d H:i') }}</div>
                        @if(!empty($stats['first_success_at']) && !empty($stats['last_success_at']))
                            @php
                                $bsFirst = \Carbon\Carbon::parse($stats['first_success_at']);
                                $bsLast = \Carbon\Carbon::parse($stats['last_success_at']);
                            @endphp
                            <div><span class="muted">First:</span> {{ $bsFirst->format('Y-m-d H:i') }}</div>
                            <div class="muted">Span: {{ $bsFirst->equalTo($bsLast) ? '—' : $bsFirst->locale('en')->diffForHumans($bsLast, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</div>
                        @endif
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

                <button class="btn" type="button" id="bs-select-toggle" title="Select several backups and download them as one ZIP">⬇ Download Multiple</button>

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
                    @php
                        $bsToday = now()->toDateString();
                        $bsYesterday = now()->subDay()->toDateString();
                    @endphp
                    <span class="filter-group">
                        <button type="button" class="filter-pill js-date-preset {{ ($from === $bsToday && $to === $bsToday) ? 'active' : '' }}"
                                data-date="{{ $bsToday }}" title="Backups created today">Today</button>
                        <button type="button" class="filter-pill js-date-preset {{ ($from === $bsYesterday && $to === $bsYesterday) ? 'active' : '' }}"
                                data-date="{{ $bsYesterday }}" title="Backups created yesterday">Yesterday</button>
                    </span>
                    @if(count($connectionLabels ?? []) > 1)
                        <select name="connection" title="Filter by database">
                            <option value="">All databases</option>
                            @foreach($connectionLabels as $bsName => $bsLabel)
                                <option value="{{ $bsName }}" @selected($connection === $bsName)>{{ $bsLabel }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select name="per_page">
                        @foreach(config('backup-station.viewer.per_page_options', [10,25,50,100]) as $opt)
                            <option value="{{ $opt }}" @selected($perPage == $opt)>{{ $opt }} / page</option>
                        @endforeach
                    </select>
                    <button class="btn" type="submit">Filter</button>
                    @if($search || ($status && $status !== 'all') || ($pinned && $pinned !== 'all') || $from || $to || $connection)
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

            <div class="bs-select-bar" id="bs-select-bar">
                <label class="bs-select-all-label">
                    <input type="checkbox" id="bs-select-all"> Select all
                </label>
                <span class="muted"><strong id="bs-select-count">0</strong> selected</span>
                <span style="margin-left:auto; display:flex; gap:8px;">
                    <button type="button" class="btn btn-sm btn-success" id="bs-select-download" disabled>↓ Download Selected</button>
                    <button type="button" class="btn btn-sm" id="bs-select-cancel">Cancel</button>
                </span>
            </div>
            <form method="POST" action="{{ route('backup-station.download-multiple') }}" id="bulk-dl-form" style="display:none">
                @csrf
                <input type="hidden" name="download_password" value="" id="bulk-dl-pw">
                <div id="bulk-dl-ids"></div>
            </form>

            <table class="bk-table">
                <thead>
                <tr>
                    <th class="bs-sel-col"></th>
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
                    @php
                        $id = $b['id'];
                        $bsDownloadable = ($b['status'] ?? null) === 'success'
                            && ($b['type'] ?? null) !== 'restore'
                            && ($b['_exists'] ?? true);
                    @endphp
                    <tr>
                        <td class="bs-sel-col">
                            @if($bsDownloadable)
                                <input type="checkbox" class="bs-row-cb" value="{{ $id }}" title="Select for download">
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('backup-station.pin') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="id" value="{{ $id }}">
                                <button type="submit" class="pin {{ empty($b['pinned']) ? 'off' : '' }}" title="{{ empty($b['pinned']) ? 'Mark' : 'Unmark' }}" style="background:none;border:none;font-size:18px;cursor:pointer;padding:2px 4px;">★</button>
                            </form>
                        </td>
                        <td>
                            @php $isRestore = ($b['type'] ?? null) === 'restore'; @endphp
                            <div class="filename">
                                @if($isRestore)<span class="badge badge-info" style="margin-right:6px">↺ Restore</span>@endif
                                {{ $b['filename'] ?? '—' }}
                            </div>
                            @if(!empty($b['note']))<div class="muted bk-note js-note" data-id="{{ $id }}" data-note="{{ $b['note'] }}" title="Click to edit note">{{ $b['note'] }}</div>@endif
                            @if(!empty($b['monthly_keep']))<span class="badge badge-info" style="margin-top:4px">Monthly</span>@endif
                            @if(!$isRestore && !empty($b['encrypted']))
                                <span class="badge badge-warning" style="margin-top:4px" title="AES-256 password protected">🔒 Encrypted</span>
                            @elseif(!$isRestore && !empty($b['filename']) && str_ends_with(strtolower($b['filename']), '.zip'))
                                <span class="badge badge-info" style="margin-top:4px" title="ZIP archive (no password)">🗜 ZIP</span>
                            @endif
                            @if($isRestore && !empty($b['restored_by']['user_name']))
                                <div class="muted" style="font-size:11px;margin-top:3px">by {{ $b['restored_by']['user_name'] }}</div>
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
                            <div class="muted">{{ \Carbon\Carbon::parse($b['created_at'])->locale('en')->diffForHumans() }}</div>
                            @if(!empty($b['last_restored_at']))
                                @php
                                    $lastStatus = $b['last_restore_status'] ?? 'success';
                                    $color = $lastStatus === 'failed' ? 'var(--danger-text)' : 'var(--info-text)';
                                    $lastUser = collect((array)($b['restores'] ?? []))->last()['user_name'] ?? null;
                                @endphp
                                <div class="muted" style="margin-top:4px;color:{{ $color }}" title="Last restore">
                                    ↺ Restored {{ \Carbon\Carbon::parse($b['last_restored_at'])->locale('en')->diffForHumans() }}
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
                                @if($isRestore)
                                    @if(!empty($b['error']))
                                        <button type="button" class="btn btn-sm btn-danger js-view-error" data-error="{{ $b['error'] }}">View error</button>
                                    @endif
                                @elseif(($b['status'] ?? null) === 'success' && $exists)
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

                                <button type="button" class="btn btn-sm js-note"
                                        data-id="{{ $id }}"
                                        data-note="{{ $b['note'] ?? '' }}"
                                        title="{{ empty($b['note']) ? 'Add a note' : 'Edit note' }}">✎ Note</button>

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
                    <tr><td colspan="8" style="padding:30px; text-align:center; color:var(--text-muted)">No backups yet. Click <strong>Run Backup Now</strong> to create one.</td></tr>
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
    <form method="POST" action="{{ route('backup-station.run') }}" class="modal" style="max-width:720px;max-height:88vh;display:flex;flex-direction:column;" data-loading="Creating backup…" id="run-form" onsubmit="return serializeRunSelection();">
        @csrf
        <input type="hidden" name="tables_structure_json" id="run-structure-json" value="">
        <input type="hidden" name="tables_data_json" id="run-data-json" value="">
        <h3>Run Backup</h3>

        @php
            $bsRunTargets = $connectionLabels ?? [];
        @endphp
        @if(count($bsRunTargets) > 1)
            <div style="margin:10px 0 12px">
                <label class="muted" style="font-size:12px">Backup target</label>
                <select name="connection" id="run-connection" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text);font-size:13px">
                    <option value="">All databases ({{ count($bsRunTargets) }})</option>
                    @foreach($bsRunTargets as $bsName => $bsLabel)
                        <option value="{{ $bsName }}">{{ $bsLabel === $bsName ? $bsName : $bsLabel . ' — ' . $bsName }}</option>
                    @endforeach
                </select>
                <div class="muted" style="font-size:11px;margin-top:4px" id="run-target-hint">Full dump of every database listed above.</div>
            </div>
        @endif

        <div id="run-tables-section" style="flex:1;min-height:0;display:flex;flex-direction:column">
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
    .bs-sel-col { display:none; width:34px; text-align:center; padding-right:0 !important; }
    body.bs-select-mode .bs-sel-col { display:table-cell; }
    .bs-sel-col input { width:15px; height:15px; cursor:pointer; vertical-align:middle; }
    .bs-select-bar { display:none; align-items:center; gap:14px; padding:10px 14px; border-bottom:1px solid var(--border); background:var(--bg); font-size:13px; }
    body.bs-select-mode .bs-select-bar { display:flex; }
    body.bs-select-mode #bs-select-toggle { display:none; }
    .bs-select-all-label { display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none; }
    .bs-select-all-label input { width:15px; height:15px; cursor:pointer; }
    body.bs-select-mode tr.bs-row-selected td { background:var(--primary-glow); }

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

    /* Searchable database dropdown (filter bar + run dialog) */
    .bs-combo { position:relative; display:inline-block; min-width:190px; }
    #run-modal .bs-combo { display:block; width:100%; margin-top:4px; }
    .bs-combo-btn { width:100%; text-align:left; padding:7px 28px 7px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text); font-size:13px; cursor:pointer; position:relative; font-family:inherit; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .bs-combo-btn:focus { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-glow); outline:none; }
    .bs-combo-btn::after { content:'▾'; position:absolute; right:9px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:11px; }
    .bs-combo-panel { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-sm); box-shadow:var(--shadow-lg); z-index:60; padding:6px; }
    .bs-combo-panel.open { display:block; }
    .bs-combo-search { width:100%; padding:7px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text); font-size:12.5px; outline:none; margin-bottom:6px; font-family:inherit; }
    .bs-combo-search:focus { border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-glow); }
    .bs-combo-list { max-height:240px; overflow-y:auto; }
    .bs-combo-opt { padding:7px 10px; border-radius:var(--radius-sm); cursor:pointer; font-size:12.5px; }
    .bs-combo-opt:hover { background:var(--hover); }
    .bs-combo-opt.active { background:var(--primary); color:#fff; }
    .bs-combo-empty { padding:10px; color:var(--text-muted); font-size:12px; text-align:center; }
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

<div class="modal-backdrop" id="note-modal">
    <form method="POST" action="{{ route('backup-station.note') }}" class="modal">
        @csrf
        <h3>Edit Note</h3>
        <input type="hidden" name="id" id="note-id">
        <label for="note-text">Note (leave empty to remove)</label>
        <textarea name="note" id="note-text" maxlength="500" rows="3" placeholder="e.g. Pre-deploy snapshot"></textarea>
        <div class="modal-actions">
            <button type="button" class="btn" onclick="closeNote()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
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

    function openNote(id, current) {
        document.getElementById('note-id').value = id;
        document.getElementById('note-text').value = current || '';
        document.getElementById('note-modal').classList.add('open');
        setTimeout(() => document.getElementById('note-text').focus(), 50);
    }
    function closeNote() { document.getElementById('note-modal').classList.remove('open'); }
    document.getElementById('note-modal').addEventListener('click', (e) => {
        if (e.target.id === 'note-modal') closeNote();
    });
    document.querySelectorAll('.js-note').forEach(el => {
        el.addEventListener('click', () => openNote(el.dataset.id, el.dataset.note));
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

    // Target selector — only rendered when more than one connection exists.
    // Value '' means "all databases": the table picker is hidden (full dumps)
    // and no table selection is submitted.
    const runConnSelect = document.getElementById('run-connection');
    const runTablesSection = document.getElementById('run-tables-section');

    function runTargetIsAll() {
        return !!runConnSelect && runConnSelect.value === '';
    }

    function syncRunTargetUi() {
        if (!runConnSelect) {
            if (!runTablesLoaded) loadRunTables();
            return;
        }
        const all = runTargetIsAll();
        runTablesSection.style.display = all ? 'none' : 'flex';
        const hint = document.getElementById('run-target-hint');
        if (hint) hint.style.display = all ? '' : 'none';
        if (!all && !runTablesLoaded) loadRunTables(runConnSelect.value);
    }

    if (runConnSelect) {
        runConnSelect.addEventListener('change', () => {
            runTablesLoaded = false;
            runTablesData = [];
            syncRunTargetUi();
        });
    }

    /* ---------- Searchable database dropdowns ----------
       Wraps a native <select> in a button + search panel. The select stays
       in the DOM (hidden) so form submission and change listeners keep
       working untouched. Only used for the connection selects, which are
       rendered exclusively when more than one database is configured. */
    function enhanceSearchableSelect(sel) {
        if (!sel || sel.dataset.bsEnhanced) return;
        sel.dataset.bsEnhanced = '1';

        const wrap = document.createElement('div');
        wrap.className = 'bs-combo';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.style.display = 'none';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bs-combo-btn';
        if (sel.title) btn.title = sel.title;
        wrap.appendChild(btn);

        const panel = document.createElement('div');
        panel.className = 'bs-combo-panel';
        panel.innerHTML = '<input type="text" class="bs-combo-search" placeholder="Search database…">'
            + '<div class="bs-combo-list"></div>';
        wrap.appendChild(panel);

        const search = panel.querySelector('.bs-combo-search');
        const listEl = panel.querySelector('.bs-combo-list');

        const syncBtn = () => {
            btn.textContent = sel.options[sel.selectedIndex]?.text || '';
        };
        syncBtn();

        function renderList(q) {
            const needle = (q || '').trim().toLowerCase();
            let html = '';
            Array.from(sel.options).forEach(o => {
                if (needle && !(o.text + ' ' + o.value).toLowerCase().includes(needle)) return;
                html += '<div class="bs-combo-opt' + (o.value === sel.value ? ' active' : '') + '" data-value="'
                    + escapeHtml(o.value) + '">' + escapeHtml(o.text) + '</div>';
            });
            listEl.innerHTML = html || '<div class="bs-combo-empty">No matching database</div>';
        }

        function openPanel() {
            panel.classList.add('open');
            search.value = '';
            renderList('');
            setTimeout(() => search.focus(), 0);
        }
        function closePanel() { panel.classList.remove('open'); }

        btn.addEventListener('click', () => {
            panel.classList.contains('open') ? closePanel() : openPanel();
        });
        search.addEventListener('input', () => renderList(search.value));
        search.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = listEl.querySelector('.bs-combo-opt');
                if (first) pick(first.dataset.value);
            } else if (e.key === 'Escape') {
                closePanel();
                btn.focus();
            }
        });
        listEl.addEventListener('click', (e) => {
            const opt = e.target.closest('.bs-combo-opt');
            if (opt) pick(opt.dataset.value);
        });

        function pick(value) {
            if (sel.value !== value) {
                sel.value = value;
                sel.dispatchEvent(new Event('change'));
            }
            syncBtn();
            closePanel();
        }

        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) closePanel();
        });
    }

    // Deep-link from the Config page: /backup-station?run=<connection>
    // preselects that database and opens the Run Backup dialog. The value
    // is set BEFORE the combo enhancement so its button label is correct.
    const bsRunParam = new URLSearchParams(window.location.search).get('run');
    if (bsRunParam !== null && runConnSelect
        && Array.from(runConnSelect.options).some(o => o.value === bsRunParam)) {
        runConnSelect.value = bsRunParam;
    }

    enhanceSearchableSelect(document.querySelector('#filter-form select[name="connection"]'));
    enhanceSearchableSelect(runConnSelect);

    if (bsRunParam !== null) {
        openRunDialog();
    }

    function openRunDialog() {
        runModal.classList.add('open');
        syncRunTargetUi();
    }
    function closeRunDialog() { runModal.classList.remove('open'); }
    runModal.addEventListener('click', (e) => {
        if (e.target.id === 'run-modal') closeRunDialog();
    });

    let runTablesData = [];                              // raw rows from API
    let runSort = { key: 'name', dir: 'asc' };           // active sort state

    function loadRunTables(conn) {
        const list = document.getElementById('run-tables-list');
        list.innerHTML = '<div class="muted" style="text-align:center;padding:30px">Loading tables…</div>';
        const url = '{{ route('backup-station.tables') }}' + (conn ? ('?connection=' + encodeURIComponent(conn)) : '');
        fetch(url, { credentials: 'same-origin' })
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
                + '<td class="col-cb"><input type="checkbox" class="run-cb-structure" value="' + safe + '" ' + sChecked + '></td>'
                + '<td class="col-cb"><input type="checkbox" class="run-cb-data" value="' + safe + '" ' + dChecked + '></td>'
                + '</tr>';
        }
        html += '</tbody></table>';

        document.getElementById('run-tables-list').innerHTML = html;

        bindRunTableEvents();
        bindRunSortHeaders();
        applyRunFilter();
        updateRunCounts();
    }

    // Collapse the per-row checkboxes into two JSON fields before submit.
    // Avoids PHP's max_input_vars (default 1000) silently dropping tables
    // when the database has many tables (e.g. 500+ → 1000+ inputs).
    function serializeRunSelection() {
        // "All databases" target (or tables not loaded yet) ⇒ submit no table
        // selection so every database gets a plain full dump.
        if (runTargetIsAll() || !runTablesLoaded) {
            document.getElementById('run-structure-json').value = '';
            document.getElementById('run-data-json').value = '';
            return true;
        }

        const structure = [];
        const data = [];
        document.querySelectorAll('.run-table-row').forEach(tr => {
            const name = tr.dataset.name;
            if (tr.querySelector('.run-cb-structure')?.checked) structure.push(name);
            if (tr.querySelector('.run-cb-data')?.checked) data.push(name);
        });
        document.getElementById('run-structure-json').value = JSON.stringify(structure);
        document.getElementById('run-data-json').value = JSON.stringify(data);
        return true;
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

    // ---- Multi-select download ----------------------------------------
    (function () {
        const toggleBtn = document.getElementById('bs-select-toggle');
        const selectAll = document.getElementById('bs-select-all');
        const countEl = document.getElementById('bs-select-count');
        const dlBtn = document.getElementById('bs-select-download');
        const cancelBtn = document.getElementById('bs-select-cancel');
        const rowCbs = Array.from(document.querySelectorAll('.bs-row-cb'));
        const needsPw = {{ config('backup-station.download_password') ? 'true' : 'false' }};

        if (!toggleBtn) return;

        function checked() { return rowCbs.filter(cb => cb.checked); }

        function refresh() {
            const n = checked().length;
            countEl.textContent = n;
            dlBtn.disabled = n === 0;
            dlBtn.textContent = '↓ Download Selected' + (n ? ' (' + n + ')' : '');
            selectAll.checked = rowCbs.length > 0 && n === rowCbs.length;
            selectAll.indeterminate = n > 0 && n < rowCbs.length;
            rowCbs.forEach(cb => cb.closest('tr').classList.toggle('bs-row-selected', cb.checked));
        }

        function setMode(on) {
            document.body.classList.toggle('bs-select-mode', on);
            if (!on) {
                rowCbs.forEach(cb => cb.checked = false);
                refresh();
            }
        }

        toggleBtn.addEventListener('click', () => setMode(true));
        cancelBtn.addEventListener('click', () => setMode(false));

        selectAll.addEventListener('change', () => {
            rowCbs.forEach(cb => cb.checked = selectAll.checked);
            refresh();
        });
        rowCbs.forEach(cb => cb.addEventListener('change', refresh));

        dlBtn.addEventListener('click', () => {
            const ids = checked().map(cb => cb.value);
            if (!ids.length) return;

            if (needsPw) {
                const pw = window.prompt('Enter the download password:');
                if (pw === null || pw === '') return;
                document.getElementById('bulk-dl-pw').value = pw;
            }

            const holder = document.getElementById('bulk-dl-ids');
            holder.innerHTML = '';
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                holder.appendChild(input);
            });
            document.getElementById('bulk-dl-form').submit();
        });

        refresh();
    })();

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

    document.querySelectorAll('.filter-pill[data-field]').forEach(btn => {
        btn.addEventListener('click', () => {
            const field = document.getElementById(btn.dataset.field);
            if (field) field.value = btn.dataset.value;
            document.getElementById('filter-form').submit();
        });
    });

    // Today / Yesterday presets — set both date bounds to the same day.
    document.querySelectorAll('.js-date-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            const form = document.getElementById('filter-form');
            form.querySelector('[name=from]').value = btn.dataset.date;
            form.querySelector('[name=to]').value = btn.dataset.date;
            form.submit();
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
