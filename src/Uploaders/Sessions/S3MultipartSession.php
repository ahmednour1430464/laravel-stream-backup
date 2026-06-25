<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders\Sessions;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;

/**
 * Mutable session holding the state of a single S3 multipart upload.
 * Parts are appended as they are uploaded, in order.
 */
final class S3MultipartSession extends WriteSession
{
    /** @var array<int, array{PartNumber: int, ETag: string}> */
    private array $parts = [];

    public function __construct(
        public readonly string $uploadId,   // S3-only
        BackupMetadata $metadata,
    ) {
        parent::__construct($metadata);
    }

    public function recordPart(int $partNumber, string $etag, int $bytes): void
    {
        $this->parts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
        $this->recordChunk($bytes);
    }

    /** @return array<int, array{PartNumber: int, ETag: string}> */
    public function parts(): array
    {
        return $this->parts;
    }
}
