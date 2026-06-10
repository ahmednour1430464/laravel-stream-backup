<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Downloaders;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DownloadDriver;
use Ahmednour\StreamBackup\Exceptions\BackupFileNotFoundException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\FileDownloadStream;

/**
 * Downloads backup files from the local filesystem.
 *
 * The file is opened as a native PHP stream resource, which is both
 * memory-efficient (no buffering) and fast (no network I/O).
 */
final class LocalDownloadDriver implements DownloadDriver
{
    public function __construct(
        private readonly string $root = '',
    ) {}

    public function assertExists(string $path): void
    {
        if ($path === '') {
            throw new BackupFileNotFoundException('Backup has no file path set.');
        }

        $localPath = $this->resolvePath($path);

        if (! file_exists($localPath)) {
            throw new BackupFileNotFoundException(
                "Backup file '{$path}' not found on local disk (resolved: {$localPath}).",
            );
        }
    }

    public function download(string $path): BackupStream
    {
        $localPath = $this->resolvePath($path);

        $handle = @fopen($localPath, 'rb');

        if ($handle === false) {
            throw new PipelineException(
                "Cannot open local backup file for reading: {$localPath}",
            );
        }

        return new FileDownloadStream($handle);
    }

    private function resolvePath(string $path): string
    {
        $path = ltrim($path, '/');

        // Ensure the resolved path stays within the root directory to prevent
        // directory traversal attacks via crafted backup paths.
        $resolved = $this->root !== '' ? rtrim($this->root, '/') . '/' . $path : $path;
        $realRoot = $this->root !== '' ? realpath($this->root) : null;
        $realResolved = realpath(dirname($resolved));

        if ($realRoot !== null && $realResolved !== false) {
            $normalizedRoot = rtrim($realRoot, '/') . '/';
            $normalizedResolved = rtrim($realResolved, '/') . '/';

            if (! str_starts_with($normalizedResolved, $normalizedRoot)) {
                throw new PipelineException(
                    "Backup path '{$path}' resolves outside the configured root directory.",
                );
            }
        }

        return $resolved;
    }
}
