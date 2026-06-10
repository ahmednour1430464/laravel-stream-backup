<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Uploaders\Sessions\LocalWriteSession;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;

final class LocalDiskUploader implements UploadDriver
{
    public function __construct(
        private readonly string $root = '',
    ) {}

    private function resolvePath(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->root !== '' ? rtrim($this->root, '/') . '/' . $path : $path;
    }

    public function initiate(BackupMetadata $metadata): WriteSession
    {
        $fullPath = $this->resolvePath($metadata->path);
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($fullPath, 'wb');
        if (! is_resource($handle)) {
            throw new PipelineException("Cannot open local path for writing: {$fullPath}");
        }

        return new LocalWriteSession($handle, $fullPath, $metadata);
    }

    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void
    {
        assert($session instanceof LocalWriteSession);

        if (stream_copy_to_stream($body, $session->handle) === false) {
            throw new PipelineException("Local write failed on chunk {$chunkNumber}.");
        }

        $session->recordChunk($size);
    }

    public function complete(WriteSession $session): UploadResult
    {
        assert($session instanceof LocalWriteSession);
        fclose($session->handle);
        return $this->buildResult($session);
    }

    public function abort(WriteSession $session): void
    {
        assert($session instanceof LocalWriteSession);
        fclose($session->handle);
        @unlink($session->localPath);
    }

    private function buildResult(LocalWriteSession $session): UploadResult
    {
        $duration = max(0.0, microtime(true) - $session->metadata->startedAt->getTimestamp());

        return new UploadResult(
            bucket:          '',
            key:             $session->localPath,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: $duration,
            checksum:        '',
        );
    }
}