<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Events;

use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\Models\Restore;
use Illuminate\Foundation\Events\Dispatchable;

class RestoreStarting
{
    use Dispatchable;

    public function __construct(
        public readonly RestoreContext $context,
        public readonly Restore $restore,
    ) {}
}
