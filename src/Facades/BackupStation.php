<?php

namespace MahmoudMhamed\BackupStation\Facades;

use Illuminate\Support\Facades\Facade;
use MahmoudMhamed\BackupStation\BackupStationService;

/**
 * @method static array runBackup(?string $connection = null, ?string $note = null)
 * @method static array loadMetadata()
 * @method static bool deleteBackup(string $id)
 * @method static array rename(string $id, string $newName)
 * @method static ?array togglePin(string $id)
 * @method static array applyRetentionPolicy()
 * @method static array stats()
 */
class BackupStation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BackupStationService::class;
    }
}
