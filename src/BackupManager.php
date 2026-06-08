<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup;

use Ahmednour\StreamBackup\Contracts\TenantResolver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Jobs\RunBackupJob;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Thin orchestrator for dispatching backup jobs. Commands and host
 * applications should talk to this class rather than constructing jobs
 * directly.
 */
class BackupManager
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly Config $config,
    ) {
    }

    /**
     * Dispatch one backup per tenant returned by the resolver.
     *
     * @return int number of jobs dispatched
     */
    public function backupAll(): int
    {
        $count = 0;
        foreach ($this->resolver->resolve() as $context) {
            $this->dispatch($context);
            $count++;
        }
        return $count;
    }

    /**
     * Dispatch a single backup for the tenant whose id or connection name
     * matches $identifier.
     */
    public function backupTenant(string $identifier): ?BackupContext
    {
        foreach ($this->resolver->resolve() as $context) {
            if ((string) $context->tenantId === $identifier
                || $context->connectionName === $identifier
                || $context->databaseName === $identifier
            ) {
                $this->dispatch($context);
                return $context;
            }
        }
        return null;
    }

    public function dispatch(BackupContext $context): void
    {
        RunBackupJob::dispatch($context)
            ->onConnection($this->config->get('stream-backup.queue.connection', 'redis'))
            ->onQueue($this->config->get('stream-backup.queue.queue', 'backups'));
    }

    /**
     * Dispatch a restore job.
     *
     * @param int             $backupId   The ID of the Backup model to restore
     * @param string[]        $tables     Specific tables to restore (empty = all)
     * @param string|null     $connection Target database connection (overrides backup's original connection if set)
     */
    public function restore(int $backupId, array $tables = [], ?string $connection = null): void
    {
        $backup = \Ahmednour\StreamBackup\Models\Backup::findOrFail($backupId);

        $connectionName = $connection ?? $backup->connection_name;

        if ($connectionName === null) {
            if ($backup->tenant_id !== null) {
                $tenants = (array) $this->config->get('stream-backup.tenants', []);
                foreach ($tenants as $t) {
                    if (($t['tenant_id'] ?? null) === $backup->tenant_id) {
                        $connectionName = $t['connection'] ?? null;
                        break;
                    }
                }
            }
            $connectionName ??= $this->config->get('database.default', 'mysql');
        }

        $context = new \Ahmednour\StreamBackup\DTOs\RestoreContext(
            backupId:       $backupId,
            tables:         $tables,
            connectionName: (string) $connectionName,
            databaseName:   $backup->database_name,
            disk:           (string) $this->config->get('stream-backup.upload.disk', 's3'),
            tenantId:       $backup->tenant_id,
        );

        \Ahmednour\StreamBackup\Jobs\RunRestoreJob::dispatch($context)
            ->onConnection($this->config->get('stream-backup.queue.connection', 'redis'))
            ->onQueue($this->config->get('stream-backup.queue.queue', 'backups'));
    }
}
