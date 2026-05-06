<?php

namespace MahmoudMhamed\BackupStation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeBackupStation
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (in_array($routeName, ['backup-station.login', 'backup-station.login.submit'], true)) {
            return $next($request);
        }

        $password = config('backup-station.viewer.password');
        if ($password !== null && $password !== '') {
            if (!$request->session()->get('backup_station_authenticated')) {
                return redirect()->route('backup-station.login');
            }
        }

        return $next($request);
    }
}
