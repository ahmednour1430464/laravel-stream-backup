<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Aws\S3\S3ClientInterface;

/**
 * S3 multipart uploader wired for DigitalOcean Spaces compatibility.
 *
 * `request_checksum_calculation = when_required` and
 * `response_checksum_validation = when_required` (set on the S3Client when
 * it is built in the service provider) are MANDATORY for Spaces — without
 * them AWS SDK >= 3.337 sends extra checksum headers that Spaces rejects
 * with MalformedXML on CompleteMultipartUpload.
 *
 * initiate() persists the UploadId to the backups row BEFORE any part is
 * uploaded, so a worker crash never leaves an orphan upload on S3 with no
 * way to abort it.
 */
final class S3MultipartUploader implements UploadDriver
{
    public function __construct(private readonly S3ClientInterface $s3)
    {
    }

    public function initiate(BackupMetadata $metadata): MultipartSession
    {
        $result = $this->s3->createMultipartUpload([
            'Bucket'      => $metadata->bucket,
            'Key'         => $metadata->path,
            'ContentType' => $metadata->contentType,
        ]);

        $uploadId = $result['UploadId'] ?? null;
        if (! is_string($uploadId) || $uploadId === '') {
            throw new PipelineException('S3 createMultipartUpload did not return an UploadId.');
        }

        Backup::query()->whereKey($metadata->backupId)->update([
            'upload_id' => $uploadId,
        ]);

        return new MultipartSession($uploadId, $metadata);
    }

    public function uploadPart(MultipartSession $session, int $partNumber, $body, int $size): void
    {
        $result = $this->s3->uploadPart([
            'Bucket'        => $session->metadata->bucket,
            'Key'           => $session->metadata->path,
            'UploadId'      => $session->uploadId,
            'PartNumber'    => $partNumber,
            'Body'          => $body,
            'ContentLength' => $size,
        ]);

        $etag = $result['ETag'] ?? null;
        if (! is_string($etag) || $etag === '') {
            throw new PipelineException(sprintf(
                'uploadPart returned no ETag for part %d of %s.',
                $partNumber,
                $session->metadata->path,
            ));
        }

        $session->recordPart($partNumber, $etag, $size);

        Backup::query()->whereKey($session->metadata->backupId)->update([
            'parts_uploaded' => $session->partCount(),
        ]);
    }

    public function complete(MultipartSession $session): UploadResult
    {
        if ($session->parts() === []) {
            throw new PipelineException(
                'Refusing to complete a multipart upload with zero parts: the compressed stream produced no data.'
            );
        }

        $this->s3->completeMultipartUpload([
            'Bucket'          => $session->metadata->bucket,
            'Key'             => $session->metadata->path,
            'UploadId'        => $session->uploadId,
            'MultipartUpload' => ['Parts' => $session->parts()],
        ]);

        $duration = max(0.0, microtime(true) - $session->metadata->startedAt->getTimestamp());

        return new UploadResult(
            bucket:          $session->metadata->bucket,
            key:             $session->metadata->path,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: $duration,
            checksum:        '', // populated by the pipeline from the ChecksumStream
        );
    }

    public function abort(MultipartSession $session): void
    {
        try {
            $this->s3->abortMultipartUpload([
                'Bucket'   => $session->metadata->bucket,
                'Key'      => $session->metadata->path,
                'UploadId' => $session->uploadId,
            ]);
        } catch (\Throwable) {
            // best effort — the stale multipart will be picked up by
            // the hourly AbortStaleMultipartUploads cleanup job.
        }
    }
}
