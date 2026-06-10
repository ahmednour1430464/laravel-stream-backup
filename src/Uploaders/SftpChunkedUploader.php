<?php

declare(strict_types=1);

// src/Uploaders/SftpChunkedUploader.php
namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Uploaders\Sessions\SftpWriteSession;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use phpseclib3\Net\SFTP;

final class SftpChunkedUploader implements UploadDriver
{
    public function __construct(private readonly SFTP $sftp) {}

    public function initiate(BackupMetadata $metadata): WriteSession
    {
        // Create or truncate the remote file once
        if (! $this->sftp->put($metadata->path, '', SFTP::RESUME)) {
            throw new PipelineException("Cannot open remote path for writing: {$metadata->path}");
        }

        // No uploadId — the open SSH channel IS the session
        return new SftpWriteSession($this->sftp, $metadata->path, $metadata);
    }

    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void
    {
        assert($session instanceof SftpWriteSession);

        // Read the chunk content from the resource the pipeline passed us
        $data = stream_get_contents($body);
        if ($data === false) {
            throw new PipelineException("Failed to read chunk {$chunkNumber} from stream.");
        }

        // RESUME|STRING appends at current EOF — no offset tracking needed
        if (! $this->sftp->put($session->remotePath, $data, SFTP::RESUME | SFTP::STRING)) {
            throw new PipelineException("SFTP write failed on chunk {$chunkNumber}.");
        }

        $session->recordChunk($size);

        Backup::query()->whereKey($session->metadata->backupId)->update([
            'parts_uploaded' => $session->partCount(),
        ]);
    }

    public function complete(WriteSession $session): UploadResult
    {
        assert($session instanceof SftpWriteSession);
        // Nothing to commit — every uploadChunk() already landed on disk
        return $this->buildResult($session);
    }

    public function abort(WriteSession $session): void
    {
        assert($session instanceof SftpWriteSession);

        try {
            $this->sftp->delete($session->remotePath);
        } catch (\Throwable) {}
    }

    private function buildResult(SftpWriteSession $session): UploadResult
    {
        $duration = max(0.0, microtime(true) - $session->metadata->startedAt->getTimestamp());

        return new UploadResult(
            bucket:          '',
            key:             $session->remotePath,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: $duration,
            checksum:        '',
        );
    }
}