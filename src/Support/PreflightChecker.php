<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Contracts\UploadDriver;

class PreflightChecker
{
    public function __construct(
        private readonly UploadDriver $uploader,
    ) {}

    public function check(): void
    {
        $this->uploader->preflight();
    }
}
