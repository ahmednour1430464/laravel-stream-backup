<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;

/**
 * Mutable session holding the state of a single S3 multipart upload.
 * Parts are appended as they are uploaded, in order.
 */
final class MultipartSession
{
    /**
     * @var array<int, array{PartNumber: int, ETag: string}>
     */
    private array $parts = [];

    private int $totalBytes = 0;

    public function __construct(
        public readonly string $uploadId,
        public readonly BackupMetadata $metadata,
    ) {
    }

    public function recordPart(int $partNumber, string $etag, int $bytes): void
    {
        $this->parts[] = [
            'PartNumber' => $partNumber,
            'ETag'       => $etag,
        ];
        $this->totalBytes += $bytes;
    }

    /**
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    public function partCount(): int
    {
        return count($this->parts);
    }
}
