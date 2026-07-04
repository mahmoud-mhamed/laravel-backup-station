<?php

namespace MahmoudMhamed\BackupStation\Contracts;

/**
 * Supplies the list of database connections to back up at runtime.
 *
 * Implement this in the host application when the set of databases is
 * dynamic — e.g. a multi-tenant app where every tenant has its own
 * database that is not declared in config/database.php.
 *
 * Register the implementation via the `connections_provider` key in
 * config/backup-station.php.
 */
interface BackupConnectionProvider
{
    /**
     * Connection names a full backup run should cover.
     *
     * Names may be regular Laravel connections (declared in
     * config/database.php) or dynamic names resolvable via configFor().
     *
     * @return string[]
     */
    public function connections(): array;

    /**
     * Database config for a dynamic connection name, using the same shape
     * as an entry in config('database.connections'). Return null when the
     * name is unknown to the provider.
     */
    public function configFor(string $name): ?array;

    /**
     * Human labels for connection names, shown in the dashboard
     * (e.g. tenant business names). Keyed by connection name; connections
     * without an entry fall back to their raw name. Return [] for none.
     *
     * @return array<string,string>
     */
    public function labels(): array;
}
