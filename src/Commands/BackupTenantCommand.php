<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Commands;

use Ahmednour\StreamBackup\BackupManager;
use Illuminate\Console\Command;

class BackupTenantCommand extends Command
{
    protected $signature = 'backup:tenant {tenant : Tenant id, connection name, or database name}';

    protected $description = 'Dispatch a streaming backup job for a single tenant.';

    public function handle(BackupManager $manager): int
    {
        $identifier = (string) $this->argument('tenant');

        $context = $manager->backupTenant($identifier);

        if ($context === null) {
            $this->error("No tenant matches '{$identifier}'.");
            return self::FAILURE;
        }

        $this->info("Dispatched backup for {$context->databaseName} (connection: {$context->connectionName}).");
        return self::SUCCESS;
    }
}
