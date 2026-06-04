<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Contracts\VerifiesMagicBytes;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Models\Backup;
use Aws\S3\S3ClientInterface;

/**
 * Post-upload sanity check: the S3 object exists, its size matches what we
 * streamed, and the first two bytes are the gzip magic number (0x1f 0x8b).
 * Catches silent backup corruption before it becomes a restore-time disaster.
 */
final class BackupVerifier
{
    public function __construct(
        private readonly S3ClientInterface $s3,
        private readonly EncryptionFactory $encryptionFactory,
    ) {
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

        $driver = $this->encryptionFactory->make($backup->encryption_driver);

        if ($driver instanceof VerifiesMagicBytes) {
            $length = $driver->magicBytesLength();

            if ($length > 0) {
                $range = $this->s3->getObject([
                    'Bucket' => $this->bucket($backup),
                    'Key'    => $backup->path,
                    'Range'  => 'bytes=0-' . ($length - 1),
                ]);

                $magic = (string) $range['Body'];
                $driver->verifyMagicBytes($magic, $backup);
            }
        }
    }

    private function bucket(Backup $backup): string
    {
        $configured = config("filesystems.disks.{$backup->disk}.bucket");
        return is_string($configured) && $configured !== '' ? $configured : $backup->disk;
    }
}
