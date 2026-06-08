<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Illuminate\Foundation\Events\Dispatchable;

class RestoreFailed
{
    use Dispatchable;

    public function __construct(
        public readonly RestoreContext $context,
        public readonly \Throwable $exception,
    ) {
    }
}
