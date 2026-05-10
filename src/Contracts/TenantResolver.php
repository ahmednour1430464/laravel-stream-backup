<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\DTOs\BackupContext;

interface TenantResolver
{
    /**
     * Yield one BackupContext per database to back up. Yielding is preferred
     * over returning a full collection so the scheduler can stream tenants
     * even when there are thousands of them.
     *
     * @return iterable<BackupContext>
     */
    public function resolve(): iterable;
}
