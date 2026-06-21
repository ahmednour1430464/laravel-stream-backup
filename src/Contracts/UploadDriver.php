<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;

interface UploadDriver
{
    /**
     * Lightweight pre-flight check: verify the destination is reachable and
     * writable. Each driver performs a write+delete of a tiny test file using
     * its own transport (S3, SFTP, local FS) so the check uses the exact same
     * path as the real upload — no Laravel Storage facade involved.
     *
     * @throws \RuntimeException when the destination is unreachable or read-only
     */
    public function preflight(): void;

    /**
     * Open/initiate the upload. Returns a session subclass specific to this driver.
     * The pipeline only holds it as WriteSession — it never inspects internals.
     */
    public function initiate(BackupMetadata $metadata): WriteSession;

    /**
     * Send one chunk. $body is a seekable resource at position 0.
     *
     * @param resource $body
     */
    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void;

    public function complete(WriteSession $session): UploadResult;

    public function abort(WriteSession $session): void;
}
