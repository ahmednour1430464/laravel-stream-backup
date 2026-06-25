<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Downloaders;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DownloadDriver;
use Ahmednour\StreamBackup\Exceptions\BackupFileNotFoundException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\S3DownloadStream;
use Aws\S3\S3ClientInterface;

/**
 * Downloads backup files from an S3-compatible object store.
 *
 * Extracted from the original RestorePipeline: the S3 GetObject body is
 * consumed as a PHP stream, never buffered entirely in memory.
 */
final class S3DownloadDriver implements DownloadDriver
{
    public function __construct(
        private readonly S3ClientInterface $s3,
        private readonly string $bucket,
    ) {}

    public function assertExists(string $path): void
    {
        if ($path === '') {
            throw new BackupFileNotFoundException('Backup has no file path set.');
        }

        try {
            $this->s3->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);
        } catch (\Throwable $e) {
            throw new BackupFileNotFoundException(
                "Backup file '{$path}' not found in bucket '{$this->bucket}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    public function download(string $path): BackupStream
    {
        $response = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $path,
            '@http' => ['stream' => true],
        ]);

        $resource = $this->extractStreamResource($response['Body']);

        return new S3DownloadStream($resource);
    }

    /**
     * Extract the underlying PHP stream resource from the S3 response body.
     *
     * @return resource
     */
    private function extractStreamResource(mixed $body): mixed
    {
        // GuzzleHttp\Psr7\Stream::detach() returns the underlying PHP resource.
        if (is_object($body) && method_exists($body, 'detach')) {
            $resource = $body->detach();
            if (is_resource($resource)) {
                return $resource;
            }
        }

        // Fallback: if the body is already a resource.
        if (is_resource($body)) {
            return $body;
        }

        throw new PipelineException(
            'Unable to extract a stream resource from the S3 response body.'
        );
    }
}
