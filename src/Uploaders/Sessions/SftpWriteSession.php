<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders\Sessions;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use phpseclib3\Net\SFTP;

final class SftpWriteSession extends WriteSession
{
    public function __construct(
        public readonly SFTP   $sftp,
        public readonly string $remotePath,
        BackupMetadata         $metadata,
    ) {
        parent::__construct($metadata);
    }
}