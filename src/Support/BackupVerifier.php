<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Models\Backup;
use Aws\S3\S3ClientInterface;

/**
 * Post-upload sanity check: the S3 object exists, its size matches what we
 * streamed, and the first two bytes are the gzip magic number (0x1f 0x8b).
 * Catches silent backup corruption before it becomes a restore-time disaster.
 */
final class BackupVerifier
{
    public function __construct(private readonly S3ClientInterface $s3)
    {
    }

    public function verify(Backup $backup): void
    {
        $head = $this->s3->headObject([
            'Bucket' => $this->bucket($backup),
            'Key'    => $backup->path,
        ]);

        $remoteSize = (int) ($head['ContentLength'] ?? 0);

        if ($backup->size !== null && $remoteSize !== (int) $backup->size) {
            throw new \RuntimeException(sprintf(
                'Backup size mismatch for %s: local=%d remote=%d',
                $backup->path,
                $backup->size,
                $remoteSize,
            ));
        }

        $range = $this->s3->getObject([
            'Bucket' => $this->bucket($backup),
            'Key'    => $backup->path,
            'Range'  => 'bytes=0-1',
        ]);

        $magic = (string) $range['Body'];
        $driver = $backup->encryption_driver;

        if ($driver === null || $driver === 'none') {
            if (strlen($magic) < 2 || $magic[0] !== "\x1f" || $magic[1] !== "\x8b") {
                throw new \RuntimeException(sprintf(
                    'Backup %s does not start with the gzip magic bytes; the object is likely corrupt.',
                    $backup->path,
                ));
            }
        } elseif ($driver === 'openssl-aes-256-gcm') {
            if (strlen($magic) < 1 || $magic[0] !== "\x01") {
                throw new \RuntimeException(sprintf(
                    'Backup %s is encrypted with openssl-aes-256-gcm but does not start with the expected version byte; the object is likely corrupt.',
                    $backup->path,
                ));
            }
        } elseif ($driver === 'sodium') {
            if (strlen($magic) < 1 || $magic[0] !== "\x02") {
                throw new \RuntimeException(sprintf(
                    'Backup %s is encrypted with sodium but does not start with the expected version byte; the object is likely corrupt.',
                    $backup->path,
                ));
            }
        }
    }

    private function bucket(Backup $backup): string
    {
        $configured = config("filesystems.disks.{$backup->disk}.bucket");
        return is_string($configured) && $configured !== '' ? $configured : $backup->disk;
    }
}
