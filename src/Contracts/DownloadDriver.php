<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\Exceptions\BackupFileNotFoundException;

/**
 * Abstraction for downloading backup files from any storage backend.
 *
 * Mirrors the UploadDriver contract: each storage driver (S3, SFTP, local)
 * provides its own implementation, and the RestorePipeline depends only on
 * this interface — never on a concrete storage SDK.
 */
interface DownloadDriver
{
    /**
     * Verify that the backup file exists on the remote storage.
     *
     * @throws BackupFileNotFoundException if the file does not exist
     */
    public function assertExists(string $path): void;

    /**
     * Open a streaming download and return a BackupStream.
     *
     * The returned stream is consumed incrementally by the restore pipeline
     * and is never buffered entirely in memory (though some drivers may
     * spool to php://temp which auto-promotes to a temp file on disk).
     *
     * @throws BackupFileNotFoundException if the file cannot be opened
     */
    public function download(string $path): BackupStream;
}
