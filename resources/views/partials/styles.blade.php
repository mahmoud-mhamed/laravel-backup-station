<style>
    :root {
        --bg: #f4f6f9; --bg-card: #ffffff; --bg-sidebar: #fbfcfd;
        --text: #1a1d26; --text-muted: #6b7280; --text-light: #9ca3af;
        --border: #e5e7eb; --border-light: #f0f1f3;
        --primary: #4f6ef7; --primary-hover: #3b5de7; --primary-light: rgba(79,110,247,0.08); --primary-glow: rgba(79,110,247,0.15);
        --hover: rgba(0,0,0,0.03);
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.04); --shadow: 0 2px 8px rgba(0,0,0,0.06); --shadow-lg: 0 8px 24px rgba(0,0,0,0.08);
        --danger-bg: #fef2f2; --danger-text: #dc2626; --danger-border: #fecaca;
        --warning-bg: #fffbeb; --warning-text: #d97706; --warning-border: #fde68a;
        --info-bg: #eff6ff; --info-text: #2563eb; --info-border: #bfdbfe;
        --success-bg: #ecfdf5; --success-text: #059669; --success-border: #bbf7d0;
        --radius: 10px; --radius-sm: 6px; --radius-lg: 14px;
        --font-mono: 'JetBrains Mono', 'SF Mono', 'Fira Code', monospace;
    }
    [data-theme="dark"] {
        --bg: #0c0f1a; --bg-card: #151929; --bg-sidebar: #111525;
        --text: #e4e7ef; --text-muted: #8b92a8; --text-light: #5a6178;
        --border: #232840; --border-light: #1d2237;
        --primary-light: rgba(79,110,247,0.12); --primary-glow: rgba(79,110,247,0.2);
        --hover: rgba(255,255,255,0.04);
        --shadow-sm: 0 1px 2px rgba(0,0,0,0.2); --shadow: 0 2px 8px rgba(0,0,0,0.3); --shadow-lg: 0 8px 24px rgba(0,0,0,0.4);
        --danger-bg: #1f0a0a; --danger-text: #f87171; --danger-border: #3b1111;
        --warning-bg: #1f1506; --warning-text: #fbbf24; --warning-border: #3b2a0a;
        --info-bg: #0a1528; --info-text: #60a5fa; --info-border: #1e3a5f;
        --success-bg: #051a0e; --success-text: #34d399; --success-border: #14532d;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5; -webkit-font-smoothing: antialiased; }
    a { color: inherit; text-decoration: none; }
    .layout { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

    .top-nav { display: flex; align-items: center; gap: 12px; padding: 0 20px; height: 52px; background: var(--bg-card); border-bottom: 1px solid var(--border); flex-shrink: 0; box-shadow: var(--shadow-sm); position: sticky; top:0; z-index:10; }
    .top-nav .brand { font-weight: 700; font-size: 15px; color: var(--primary); display: flex; align-items: center; gap: 8px; letter-spacing: -0.01em; }
    .top-nav .brand svg { width: 20px; height: 20px; }
    .nav-links { display: flex; gap: 2px; margin-left: 20px; background: var(--bg); border-radius: var(--radius-sm); padding: 3px; }
    .nav-links a { padding: 5px 14px; border-radius: 5px; font-size: 13px; font-weight: 500; color: var(--text-muted); transition: all 0.2s; }
    .nav-links a:hover { color: var(--text); }
    .nav-links a.active { background: var(--bg-card); color: var(--primary); box-shadow: var(--shadow-sm); font-weight: 600; }
    .nav-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }
    .nav-env { font-size: 11px; color: var(--text-light); background: var(--bg); padding: 3px 10px; border-radius: 20px; font-weight: 500; }
    .theme-toggle, .nav-btn { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 6px 10px; cursor: pointer; color: var(--text-muted); font-size: 13px; }
    .theme-toggle:hover, .nav-btn:hover { background: var(--hover); border-color: var(--primary); color: var(--primary); }

    .container { flex: 1; overflow-y: auto; padding: 20px; width: 100%; }
    .container::-webkit-scrollbar { width: 8px; }
    .container::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; }
    .top-nav { position: relative; }

    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); }
    .card-pad { padding: 18px; }

    .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; }
    .stat .label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
    .stat .value { font-size: 22px; font-weight: 700; margin-top: 4px; color: var(--text); }
    .stat .sub { font-size: 11px; color: var(--text-light); margin-top: 2px; }

    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 14px; border-bottom: 1px solid var(--border); }
    .toolbar input[type=text], .toolbar input[type=date], .toolbar select { padding: 7px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); color: var(--text); font-size: 13px; outline: none; font-family: inherit; }
    .toolbar input[type=text]:focus, .toolbar input[type=date]:focus, .toolbar select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
    .toolbar label { display: inline-flex; align-items: center; gap: 5px; }

    .filter-group { display: inline-flex; align-items: center; gap: 4px; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 3px; }
    .filter-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; padding: 0 8px 0 10px; }
    .filter-pill { padding: 5px 12px; border: 0; background: transparent; color: var(--text-muted); font-size: 12px; font-weight: 500; border-radius: 999px; cursor: pointer; transition: all 0.15s; font-family: inherit; }
    .filter-pill:hover { color: var(--text); }
    .filter-pill.active { background: var(--bg-card); color: var(--primary); box-shadow: var(--shadow-sm); font-weight: 600; }

    .btn { padding: 7px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; cursor: pointer; background: var(--bg-card); color: var(--text); display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-weight: 500; }
    .btn:hover { background: var(--hover); }
    .btn-sm { padding: 4px 9px; font-size: 12px; }
    .btn-primary { background: var(--primary); color: white; border-color: var(--primary); }
    .btn-primary:hover { background: var(--primary-hover); box-shadow: 0 2px 8px var(--primary-glow); }
    .btn-danger { color: var(--danger-text); border-color: var(--danger-border); }
    .btn-danger:hover { background: var(--danger-bg); }
    .btn-success { color: var(--success-text); border-color: var(--success-border); }
    .btn-success:hover { background: var(--success-bg); }

    table.bk-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.bk-table th { text-align: left; padding: 10px 14px; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; background: var(--bg); }
    table.bk-table td { padding: 12px 14px; border-bottom: 1px solid var(--border-light); vertical-align: middle; }
    table.bk-table tr:hover td { background: var(--hover); }
    .filename { font-family: var(--font-mono); font-size: 12.5px; color: var(--text); }
    .muted { color: var(--text-muted); font-size: 12px; }
    .row-actions { display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; }

    .badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-success { background: var(--success-bg); color: var(--success-text); }
    .badge-danger { background: var(--danger-bg); color: var(--danger-text); }
    .badge-info { background: var(--info-bg); color: var(--info-text); }
    .badge-warning { background: var(--warning-bg); color: var(--warning-text); }

    .flash { padding: 10px 16px; font-size: 13px; border-radius: var(--radius-sm); margin: 10px 0; font-weight: 500; }
    .flash-success { background: var(--success-bg); color: var(--success-text); border: 1px solid var(--success-border); }
    .flash-error { background: var(--danger-bg); color: var(--danger-text); border: 1px solid var(--danger-border); }

    .pagination-bar { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid var(--border); background: var(--bg-card); font-size: 12px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }
    .page-links { display: flex; gap: 3px; }
    .page-links a, .page-links span { padding: 5px 11px; border: 1px solid var(--border); border-radius: var(--radius-sm); text-decoration: none; color: var(--text); font-size: 12px; font-weight: 500; transition: all 0.2s; }
    .page-links a:hover { background: var(--primary-light); border-color: var(--primary); color: var(--primary); }
    .page-links span.current { background: var(--primary); color: #fff; border-color: var(--primary); }
    .page-links span.disabled { opacity: 0.4; cursor: default; }
    .per-page-select { padding: 5px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 12px; background: var(--bg); color: var(--text); cursor: pointer; }

    .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 100; }
    .modal-backdrop.open { display: flex; }
    .modal { background: var(--bg-card); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); width: 100%; max-width: 460px; padding: 22px; }
    .modal h3 { margin-bottom: 14px; font-size: 16px; }
    .modal label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; }
    .modal input { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg); color: var(--text); font-size: 13px; outline: none; }
    .modal input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
    .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

    .pin { color: var(--warning-text); cursor: pointer; }
    .pin.off { color: var(--text-light); }
    .pin-indicator { color: var(--warning-text); margin-right: 4px; font-size: 12px; }

    /* Loading overlay */
    .bs-loading { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 200; backdrop-filter: blur(2px); }
    .bs-loading.open { display: flex; }
    .bs-loading .box { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); padding: 28px 36px; display: flex; flex-direction: column; align-items: center; gap: 14px; min-width: 260px; }
    .bs-spinner { width: 44px; height: 44px; border: 3px solid var(--border); border-top-color: var(--primary); border-radius: 50%; animation: bs-spin 0.7s linear infinite; }
    @keyframes bs-spin { to { transform: rotate(360deg); } }
    .bs-loading .title { font-size: 14px; font-weight: 600; color: var(--text); }
    .bs-loading .sub { font-size: 12px; color: var(--text-muted); }
</style>
