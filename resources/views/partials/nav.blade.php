<nav class="top-nav">
    <a href="{{ route('backup-station.index') }}" class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>
        </svg>
        Backup Station
    </a>
    <div class="nav-links">
        <a href="{{ route('backup-station.index') }}" class="{{ request()->routeIs('backup-station.index') ? 'active' : '' }}">Backups</a>
        <a href="{{ route('backup-station.databases') }}" class="{{ request()->routeIs('backup-station.databases') ? 'active' : '' }}">Databases</a>
        <a href="{{ route('backup-station.forecast') }}" class="{{ request()->routeIs('backup-station.forecast') ? 'active' : '' }}">Forecast</a>
        <a href="{{ route('backup-station.config') }}" class="{{ request()->routeIs('backup-station.config') ? 'active' : '' }}">Config</a>
        <a href="{{ route('backup-station.about') }}" class="{{ request()->routeIs('backup-station.about') ? 'active' : '' }}">About</a>
    </div>
    <div class="nav-right">
        <span class="nav-env">{{ app()->environment() }}</span>
        <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme"><span id="theme-icon">☾</span></button>
        @if(config('backup-station.viewer.password'))
            <form method="POST" action="{{ route('backup-station.logout') }}">@csrf<button class="nav-btn" type="submit">Logout</button></form>
        @endif
    </div>
</nav>

@php
    $bsPublicDisk = app(\MahmoudMhamed\BackupStation\BackupStationService::class)->publicDiskName();
@endphp
@if($bsPublicDisk)
    <div style="background:var(--danger-bg, #fef2f2);border-bottom:1px solid var(--danger-border, #fecaca);color:var(--danger-text, #b91c1c);padding:10px 24px;font-size:13px;display:flex;gap:10px;align-items:center;line-height:1.5;">
        <span style="font-size:16px">⚠️</span>
        <span>
            <strong>Backups are stored on a publicly accessible disk (<code>{{ $bsPublicDisk }}</code>).</strong>
            Anyone who guesses the file URL can download your database dumps.
            Set <code>BACKUP_STATION_DISK</code> to a private disk (e.g. <code>local</code>, <code>s3</code>) in your .env.
        </span>
    </div>
@endif
