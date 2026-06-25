<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders\Sessions;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;

final class LocalWriteSession extends WriteSession
{
    /** @param resource $handle */
    public function __construct(
        public readonly mixed $handle,     // local-only: open fopen handle
        public readonly string $localPath,
        BackupMetadata $metadata,
    ) {
        parent::__construct($metadata);
    }
}
