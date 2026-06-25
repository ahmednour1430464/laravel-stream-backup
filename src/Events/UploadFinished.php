<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Illuminate\Foundation\Events\Dispatchable;

class UploadFinished
{
    use Dispatchable;

    public function __construct(
        public readonly BackupContext $context,
        public readonly UploadResult $result
    ) {}
}
