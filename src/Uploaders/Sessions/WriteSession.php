<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders\Sessions;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;

/**
 * Base session. Holds only what every driver needs.
 * Driver-specific state (uploadId, ETags, file handles) lives in subclasses.
 */
abstract class WriteSession
{
    private int $totalBytes  = 0;
    private int $partCount   = 0;

    public function __construct(
        public readonly BackupMetadata $metadata,
    ) {}

    public function recordChunk(int $bytes): void
    {
        $this->totalBytes += $bytes;
        $this->partCount++;
    }

    public function totalBytes(): int  { return $this->totalBytes; }
    public function partCount(): int   { return $this->partCount; }
}