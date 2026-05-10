<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Compression;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Support\BinaryLocator;

final class GzipDriver implements CompressionDriver
{
    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly int $level = 4,
    ) {
    }

    public function buildCommand(): array
    {
        $binary = $this->locator->locate('gzip');
        $level  = max(1, min(9, $this->level));

        return [$binary, "-{$level}", '-c'];
    }

    public function name(): string
    {
        return 'gzip';
    }
}
