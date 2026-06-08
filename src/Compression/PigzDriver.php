<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Compression;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Support\BinaryLocator;

final class PigzDriver implements CompressionDriver
{
    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly int $level = 4,
    ) {
    }

    public function buildCommand(): array
    {
        $binary = $this->locator->locate('pigz');
        $level  = max(1, min(9, $this->level));

        return [$binary, "-{$level}", '-c'];
    }

    public function buildDecompressCommand(): array
    {
        return [$this->locator->locate('pigz'), '-d', '-c'];
    }

    public function name(): string
    {
        return 'pigz';
    }
}
