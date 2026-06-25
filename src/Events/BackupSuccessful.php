<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;

class BackupSuccessful
{
    use Dispatchable;

    public function __construct(
        public readonly BackupContext $context,
        public readonly Backup $backup
    ) {}
}
