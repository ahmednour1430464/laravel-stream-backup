<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

final class UploadResult
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $key,
        public readonly int $sizeBytes,
        public readonly int $partCount,
        public readonly float $durationSeconds,
        public readonly string $checksum,
    ) {}

    public function speedMbps(): float
    {
        if ($this->durationSeconds <= 0.0) {
            return 0.0;
        }

        return round(($this->sizeBytes * 8) / 1_000_000 / $this->durationSeconds, 2);
    }
}
