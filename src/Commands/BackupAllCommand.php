<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Commands;

use Ahmednour\StreamBackup\BackupManager;
use Illuminate\Console\Command;

class BackupAllCommand extends Command
{
    protected $signature = 'backup:all';

    protected $description = 'Dispatch a streaming backup job for every tenant returned by the TenantResolver.';

    public function handle(BackupManager $manager): int
    {
        $dispatched = $manager->backupAll();

        $this->info("Dispatched {$dispatched} backup job(s).");
        return self::SUCCESS;
    }
}
