<?php

namespace MahmoudMhamed\BackupStation\Exceptions;

use RuntimeException;

/**
 * The backups on the storage disk already use more than
 * `backup-station.import.total_quota_kb` allows.
 */
class QuotaExceededException extends RuntimeException
{
}
