<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Illuminate\Foundation\Events\Dispatchable;

class BackupFailed
{
    use Dispatchable;

    public function __construct(
        public readonly BackupContext $context,
        public readonly \Throwable $exception
    ) {}
}
