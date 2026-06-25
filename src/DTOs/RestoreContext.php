<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

/**
 * Immutable context for a restore operation.
 *
 * @param  string[]  $tables  Tables to restore (empty = full restore)
 */
final class RestoreContext
{
    /**
     * @param  int  $backupId  The Backup model ID to restore from
     * @param  string[]  $tables  Tables to restore (empty = full restore)
     * @param  string  $connectionName  Target Laravel DB connection
     * @param  string  $databaseName  Target database name
     * @param  string  $disk  Storage disk to download from
     * @param  int|string|null  $tenantId  Optional tenant identifier
     */
    public function __construct(
        public readonly int $backupId,
        public readonly array $tables,
        public readonly string $connectionName,
        public readonly string $databaseName,
        public readonly string $disk,
        public readonly int|string|null $tenantId = null,
    ) {}
}
