<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Commands;

use Ahmednour\StreamBackup\Jobs\AbortStaleMultipartUploads;
use Ahmednour\StreamBackup\Jobs\BackupCleanupJob;
use Illuminate\Console\Command;

class BackupCleanupCommand extends Command
{
    protected $signature = 'backup:cleanup {--sync : Run synchronously instead of dispatching to the queue}';

    protected $description = 'Prune expired backups per the retention policy and abort stale S3 multipart uploads.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            dispatch_sync(new BackupCleanupJob());
            dispatch_sync(new AbortStaleMultipartUploads());
            $this->info('Cleanup ran synchronously.');
            return self::SUCCESS;
        }

        BackupCleanupJob::dispatch();
        AbortStaleMultipartUploads::dispatch();
        $this->info('Cleanup jobs dispatched.');
        return self::SUCCESS;
    }
}
