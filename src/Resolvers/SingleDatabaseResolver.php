<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Resolvers;

use Ahmednour\StreamBackup\Contracts\TenantResolver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Fallback resolver used when config('stream-backup.tenants') is empty:
 * derives one BackupContext from config('database.default'). Lets
 * backup:all work out of the box on non-multi-tenant apps.
 */
final class SingleDatabaseResolver implements TenantResolver
{
    public function __construct(private readonly Config $config) {}

    public function resolve(): iterable
    {
        $connection = (string) $this->config->get('database.default', 'mysql');
        $database = (string) $this->config->get("database.connections.{$connection}.database", $connection);
        $disk = (string) $this->config->get('stream-backup.default_disk', 'spaces');

        yield new BackupContext(
            tenantId: null,
            databaseName: $database,
            connectionName: $connection,
            disk: $disk,
        );
    }
}
