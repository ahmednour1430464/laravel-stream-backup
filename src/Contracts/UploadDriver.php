<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Uploaders\MultipartSession;

interface UploadDriver
{
    public function initiate(BackupMetadata $metadata): MultipartSession;

    /**
     * Upload a single part. $body must be a seekable resource positioned at 0.
     *
     * @param resource $body
     */
    public function uploadPart(MultipartSession $session, int $partNumber, $body, int $size): void;

    public function complete(MultipartSession $session): UploadResult;

    public function abort(MultipartSession $session): void;
}
