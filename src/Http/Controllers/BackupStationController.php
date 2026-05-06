<?php

namespace MahmoudMhamed\BackupStation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use MahmoudMhamed\BackupStation\BackupStationService;
use Throwable;

class BackupStationController extends Controller
{
    public function __construct(protected BackupStationService $service)
    {
    }

    public function login()
    {
        if (!config('backup-station.viewer.password')) {
            return redirect()->route('backup-station.index');
        }

        return view('backup-station::login');
    }

    public function authenticate(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $expected = (string) config('backup-station.viewer.password');
        $given = (string) $request->input('password');

        // Hashed password (preferred): use constant-time Hash::check.
        // Plain password (back-compat): use hash_equals for constant-time compare.
        $matches = (Hash::info($expected)['algoName'] !== 'unknown')
            ? Hash::check($given, $expected)
            : hash_equals($expected, $given);

        if (!$matches) {
            return back()->withErrors(['password' => 'Invalid password.']);
        }

        $request->session()->regenerate();
        $request->session()->put('backup_station_authenticated', true);
        return redirect()->route('backup-station.index');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('backup_station_authenticated');
        return redirect()->route('backup-station.login');
    }

    public function index(Request $request)
    {
        $entries = $this->service->loadMetadata();

        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $pinned = $request->query('pinned');                // all | pinned | unpinned
        $from = trim((string) $request->query('from', '')); // Y-m-d
        $to = trim((string) $request->query('to', ''));     // Y-m-d

        $fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : null;
        $toTs = $to !== '' ? strtotime($to . ' 23:59:59') : null;

        $filtered = array_filter($entries, function ($e) use ($status, $search, $pinned, $fromTs, $toTs) {
            if ($status && $status !== 'all' && ($e['status'] ?? null) !== $status) {
                return false;
            }
            if ($search !== '') {
                $hay = strtolower(($e['filename'] ?? '') . ' ' . ($e['database'] ?? '') . ' ' . ($e['note'] ?? ''));
                if (!str_contains($hay, strtolower($search))) {
                    return false;
                }
            }
            if ($pinned === 'pinned' && empty($e['pinned'])) return false;
            if ($pinned === 'unpinned' && !empty($e['pinned'])) return false;

            if ($fromTs !== null || $toTs !== null) {
                $ts = strtotime((string) ($e['created_at'] ?? ''));
                if ($ts === false) return false;
                if ($fromTs !== null && $ts < $fromTs) return false;
                if ($toTs !== null && $ts > $toTs) return false;
            }
            return true;
        });

        // Sort newest first by creation date.
        usort($filtered, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $perPage = (int) $request->query('per_page', config('backup-station.viewer.per_page', 25));
        $page = max(1, (int) $request->query('page', 1));
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        // Annotate page items with file existence — only when cheap.
        // On remote disks (S3, etc.) each check is a HEAD request, so the
        // default is to skip them unless explicitly enabled in config.
        $shouldCheck = config('backup-station.viewer.check_files_exist');
        if ($shouldCheck === null) {
            $shouldCheck = !$this->service->isRemoteDisk();
        }

        foreach ($items as &$item) {
            // Restore-type rows reference a source backup; skip the disk check.
            if (($item['type'] ?? null) === 'restore') {
                $item['_exists'] = true;
                continue;
            }
            $item['_exists'] = $shouldCheck
                ? (!empty($item['filename']) && $this->service->fileExists($item['filename']))
                : true;
        }
        unset($item);

        $paginator = new LengthAwarePaginator(
            $items,
            count($filtered),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('backup-station::dashboard', [
            'paginator' => $paginator,
            'stats' => $this->service->stats(),
            'service' => $this->service,
            'status' => $status,
            'search' => $search,
            'perPage' => $perPage,
            'pinned' => $pinned,
            'from' => $from,
            'to' => $to,
            'dbSize' => $this->service->databaseSize(),
        ]);
    }

    public function run(Request $request)
    {
        $note = $request->input('note');

        $clean = function ($v) {
            if (!is_array($v)) return null;
            $v = array_values(array_filter(array_map(fn ($t) => trim((string) $t), $v)));
            return $v;
        };

        $structure = $clean($request->input('tables_structure'));
        $data = $clean($request->input('tables_data'));

        $overrides = [];
        if ($structure !== null || $data !== null) {
            $overrides['tables_structure'] = $structure ?? [];
            $overrides['tables_data'] = $data ?? [];
        } else {
            // Legacy fallback if a caller still posts `tables` + `mode`.
            $tables = $clean($request->input('tables'));
            $mode = $request->input('mode', 'full');
            if (!in_array($mode, ['full', 'structure', 'data'], true)) $mode = 'full';
            if ($tables !== null) {
                $overrides['tables'] = $tables;
                $overrides['mode'] = $mode;
            }
        }

        if (config('backup-station.queue.enabled')) {
            \MahmoudMhamed\BackupStation\Jobs\RunBackupJob::dispatch(null, $note ?: null, $overrides);
            return back()->with('flash', 'Backup queued — it will run in the background.');
        }

        try {
            $created = $this->service->runBackup(null, $note ?: null, $overrides);
            return back()->with('flash', count($created) . ' backup(s) created.');
        } catch (Throwable $e) {
            return back()->with('flash_error', 'Backup failed: ' . $e->getMessage());
        }
    }

    public function tables(Request $request)
    {
        try {
            $tables = $this->service->listTables($request->query('connection') ?: null);
            return response()->json(['tables' => $tables]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function import(Request $request)
    {
        abort_unless((bool) config('backup-station.allow_import', false), 403, 'Import is disabled.');

        $request->validate([
            'file' => 'required|file|max:' . (int) config('backup-station.import.max_upload_kb', 1024 * 1024),
            'note' => 'nullable|string|max:500',
            'import_password' => 'nullable|string',
        ]);

        if (!$this->confirmationOk(config('backup-station.import_password'), $request->input('import_password'))) {
            return back()->with('flash_error', 'Invalid import confirmation password.');
        }

        $upload = $request->file('file');

        try {
            $this->service->import(
                $upload->getRealPath(),
                $upload->getClientOriginalName(),
                $request->input('note') ?: null
            );
            return back()->with('flash', 'Backup imported successfully.');
        } catch (Throwable $e) {
            return back()->with('flash_error', 'Import failed: ' . $e->getMessage());
        }
    }

    public function download(Request $request, string $id)
    {
        $entry = $this->service->findById($id);
        abort_unless($entry && ($entry['status'] ?? null) === 'success', 404);

        // If a download password is configured, only POST (with the password)
        // is allowed — GET links can leak via referrer / browser history.
        $required = (string) config('backup-station.download_password', '');
        if ($required !== '') {
            if (!$request->isMethod('POST')) {
                abort(405, 'Use the dashboard download form.');
            }
            if (!$this->confirmationOk($required, $request->input('download_password'))) {
                return back()->with('flash_error', 'Invalid download password.');
            }
        }

        try {
            return $this->service->downloadResponse($entry);
        } catch (Throwable) {
            abort(404);
        }
    }

    public function delete(Request $request)
    {
        abort_unless((bool) config('backup-station.allow_delete', true), 403, 'Delete is disabled.');

        $this->service->deleteBackup($request->input('id'));
        return back()->with('flash', 'Backup deleted.');
    }

    public function deleteMultiple(Request $request)
    {
        abort_unless((bool) config('backup-station.allow_delete', true), 403, 'Delete is disabled.');

        $ids = (array) $request->input('ids', []);
        $count = 0;
        foreach ($ids as $id) {
            if ($this->service->deleteBackup($id)) $count++;
        }
        return back()->with('flash', "{$count} backup(s) deleted.");
    }

    public function rename(Request $request)
    {
        $request->validate(['id' => 'required|string', 'name' => 'required|string|max:200']);
        try {
            $this->service->rename($request->input('id'), $request->input('name'));
            return back()->with('flash', 'Backup renamed.');
        } catch (Throwable $e) {
            return back()->with('flash_error', $e->getMessage());
        }
    }

    public function pin(Request $request)
    {
        $this->service->togglePin($request->input('id'));
        return back()->with('flash', 'Mark toggled.');
    }

    public function restore(Request $request)
    {
        abort_unless((bool) config('backup-station.allow_restore', false), 403, 'Restore is disabled.');

        $request->validate([
            'id' => 'required|string',
            'restore_password' => 'nullable|string',
        ]);

        if (!$this->confirmationOk(config('backup-station.restore_password'), $request->input('restore_password'))) {
            return back()->with('flash_error', 'Invalid restore confirmation password.');
        }

        try {
            $this->service->restore($request->input('id'));
            return back()->with('flash', 'Backup restored successfully.');
        } catch (Throwable $e) {
            return back()->with('flash_error', 'Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Constant-time check of an optional confirmation password.
     * When no password is configured, returns true (gate disabled).
     */
    protected function confirmationOk(?string $expected, ?string $given): bool
    {
        $expected = (string) $expected;
        if ($expected === '') return true;

        $given = (string) $given;
        return (Hash::info($expected)['algoName'] !== 'unknown')
            ? Hash::check($given, $expected)
            : hash_equals($expected, $given);
    }

    public function cleanup()
    {
        abort_unless((bool) config('backup-station.allow_delete', true), 403, 'Delete is disabled.');

        $deleted = $this->service->applyRetentionPolicy();
        return back()->with('flash', count($deleted) . ' backup(s) pruned by retention policy.');
    }

    public function clearAll()
    {
        abort_unless((bool) config('backup-station.allow_delete', true), 403, 'Delete is disabled.');

        foreach ($this->service->loadMetadata() as $entry) {
            $this->service->deleteBackup($entry['id']);
        }
        return back()->with('flash', 'All backups cleared.');
    }

    public function config()
    {
        return view('backup-station::config', ['config' => config('backup-station')]);
    }

    public function forecast(Request $request)
    {
        $days = (int) $request->query('days', 7);
        $days = max(1, min(90, $days));

        $forecast = $this->service->forecastRetention($days);

        return view('backup-station::forecast', [
            'forecast' => $forecast,
            'days' => $days,
            'service' => $this->service,
        ]);
    }

    public function about()
    {
        return view('backup-station::about');
    }
}
