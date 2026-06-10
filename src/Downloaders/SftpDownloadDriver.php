<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Downloaders;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DownloadDriver;
use Ahmednour\StreamBackup\Exceptions\BackupFileNotFoundException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\FileDownloadStream;
use phpseclib3\Net\SFTP;

/**
 * Downloads backup files from an SFTP server.
 *
 * phpseclib3 does not support true incremental streaming reads, so the file
 * is spooled into a php://temp stream (auto-promotes to a temp file on disk
 * once it exceeds 2 MB) before being returned as a BackupStream.
 */
final class SftpDownloadDriver implements DownloadDriver
{
    public function __construct(
        private readonly SFTP $sftp,
        private readonly string $root = '',
    ) {}

    public function assertExists(string $path): void
    {
        if ($path === '') {
            throw new BackupFileNotFoundException('Backup has no file path set.');
        }

        $remotePath = $this->resolvePath($path);

        if (! $this->sftp->is_file($remotePath)) {
            throw new BackupFileNotFoundException(
                "Backup file '{$path}' not found on SFTP server (resolved: {$remotePath}).",
            );
        }
    }

    public function download(string $path): BackupStream
    {
        $remotePath = $this->resolvePath($path);

        // Spool the remote file into a php://temp stream.
        // php://temp auto-promotes to a real temp file once the buffer
        // exceeds 2 MB, so this works for large backups without OOM.
        $tempStream = fopen('php://temp', 'r+b');

        if ($tempStream === false) {
            throw new PipelineException('Failed to open php://temp for SFTP download.');
        }

        $result = $this->sftp->get($remotePath, $tempStream);

        if ($result === false) {
            @fclose($tempStream);
            throw new BackupFileNotFoundException(
                "Failed to download backup file '{$path}' from SFTP (resolved: {$remotePath}).",
            );
        }

        rewind($tempStream);

        return new FileDownloadStream($tempStream);
    }

    private function resolvePath(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->root !== '' ? rtrim($this->root, '/') . '/' . $path : $path;
    }
}
