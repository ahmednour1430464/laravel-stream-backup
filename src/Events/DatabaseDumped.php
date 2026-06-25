<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Illuminate\Foundation\Events\Dispatchable;

class DatabaseDumped
{
    use Dispatchable;

    public function __construct(
        public readonly BackupContext $context
    ) {}
}
