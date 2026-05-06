<?php

use Illuminate\Support\Facades\Route;
use MahmoudMhamed\BackupStation\Http\Controllers\BackupStationController;

Route::group([
    'prefix' => config('backup-station.viewer.route_prefix', 'backup-station'),
    'middleware' => 'backup-station',
], function () {

    $actionThrottle = 'bs.throttle:' . config('backup-station.viewer.action_throttle', '10,1');
    $restoreThrottle = 'bs.throttle:' . config('backup-station.viewer.restore_throttle', '3,5');

    // Auth
    Route::get('/login', [BackupStationController::class, 'login'])->name('backup-station.login');
    Route::post('/login', [BackupStationController::class, 'authenticate'])
        ->middleware('bs.throttle:' . config('backup-station.viewer.login_throttle', '5,1'))
        ->name('backup-station.login.submit');
    Route::post('/logout', [BackupStationController::class, 'logout'])->name('backup-station.logout');

    // Read-only
    Route::get('/', [BackupStationController::class, 'index'])->name('backup-station.index');
    Route::match(['GET', 'POST'], '/download/{id}', [BackupStationController::class, 'download'])
        ->middleware($actionThrottle)
        ->name('backup-station.download');
    Route::get('/config', [BackupStationController::class, 'config'])->name('backup-station.config');
    Route::get('/forecast', [BackupStationController::class, 'forecast'])->name('backup-station.forecast');
    Route::get('/tables', [BackupStationController::class, 'tables'])->name('backup-station.tables');
    Route::get('/about', [BackupStationController::class, 'about'])->name('backup-station.about');

    // Write actions — throttled
    Route::middleware($actionThrottle)->group(function () {
        Route::post('/run', [BackupStationController::class, 'run'])->name('backup-station.run');
        Route::post('/import', [BackupStationController::class, 'import'])->name('backup-station.import');
        Route::post('/delete', [BackupStationController::class, 'delete'])->name('backup-station.delete');
        Route::post('/delete-multiple', [BackupStationController::class, 'deleteMultiple'])->name('backup-station.delete-multiple');
        Route::post('/rename', [BackupStationController::class, 'rename'])->name('backup-station.rename');
        Route::post('/pin', [BackupStationController::class, 'pin'])->name('backup-station.pin');
        Route::post('/cleanup', [BackupStationController::class, 'cleanup'])->name('backup-station.cleanup');
        Route::post('/clear-all', [BackupStationController::class, 'clearAll'])->name('backup-station.clear-all');
    });

    // Restore — stricter throttle
    Route::post('/restore', [BackupStationController::class, 'restore'])
        ->middleware($restoreThrottle)
        ->name('backup-station.restore');
});
