<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\DTOs\BackupContext;

interface DatabaseDumper
{
    /**
     * Start a database dump process and return a non-blocking stream of
     * the raw SQL bytes. The returned stream's close() must validate the
     * dump process exit code.
     */
    public function dump(BackupContext $context): BackupStream;
}
