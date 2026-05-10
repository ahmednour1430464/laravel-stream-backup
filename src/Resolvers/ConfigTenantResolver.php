<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Resolvers;

use Ahmednour\StreamBackup\Contracts\TenantResolver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves one BackupContext per entry in config('stream-backup.tenants').
 * Each entry must provide at minimum a 'connection' key; 'database',
 * 'tenant_id' and 'disk' fall back to sensible defaults.
 */
final class ConfigTenantResolver implements TenantResolver
{
    public function __construct(private readonly Config $config)
    {
    }

    public function resolve(): iterable
    {
        $tenants = (array) $this->config->get('stream-backup.tenants', []);
        $disk    = (string) $this->config->get('stream-backup.default_disk', 'spaces');

        foreach ($tenants as $tenant) {
            $connection = (string) ($tenant['connection'] ?? '');
            if ($connection === '') {
                continue;
            }

            $database = (string) ($tenant['database']
                ?? $this->config->get("database.connections.{$connection}.database", $connection));

            yield new BackupContext(
                tenantId:       $tenant['tenant_id'] ?? null,
                databaseName:   $database,
                connectionName: $connection,
                disk:           (string) ($tenant['disk'] ?? $disk),
                timeoutSeconds: (int) ($tenant['timeout'] ?? 0),
                extraDumpFlags: (array) ($tenant['extra_dump_flags'] ?? []),
            );
        }
    }
}
