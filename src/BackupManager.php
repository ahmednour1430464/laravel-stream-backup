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
}
