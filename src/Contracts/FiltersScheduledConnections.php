<?php

namespace MahmoudMhamed\BackupStation\Contracts;

/**
 * Optional companion to BackupConnectionProvider: narrows the databases an
 * AUTOMATIC (scheduled) run covers using host-application rules — e.g.
 * only tenants with an active subscription.
 *
 * Manual runs, the dashboard listings, labels, downloads and restores keep
 * using the full connections() list, so filtered-out databases stay
 * visible and can still be backed up on demand.
 *
 * Applied before the backup-scope settings (only / exclude).
 */
interface FiltersScheduledConnections
{
    /**
     * @param  string[]  $connections  Every connection from connections().
     * @return string[] The subset automatic runs should back up.
     */
    public function scheduledConnections(array $connections): array;
}
