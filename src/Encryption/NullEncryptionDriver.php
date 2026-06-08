<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Contracts\VerifiesMagicBytes;
use Ahmednour\StreamBackup\Models\Backup;

/**
 * Passthrough (Null Object) encryption driver.
 *
 * Returns the inner stream unchanged — no wrapping, no allocation,
 * no code on the hot path. Used when encryption.driver = 'none'.
 *
 * This eliminates all null-checks in the pipeline: the same code path
 * runs whether encryption is enabled or not; the driver handles the
 * difference transparently.
 */
final class NullEncryptionDriver implements EncryptionDriver, VerifiesMagicBytes
{
    public function spawn(BackupStream $inner, string $key): BackupStream
    {
        return $inner;
    }

    public function spawnDecrypt(BackupStream $inner, string $key): BackupStream
    {
        return $inner;
    }

    public function name(): string
    {
        return 'none';
    }

    public function keyLength(): int
    {
        return 0;
    }

    public function magicBytesLength(): int
    {
        return 2;
    }

    public function verifyMagicBytes(string $magic, Backup $backup): void
    {
        if (strlen($magic) < 2 || $magic[0] !== "\x1f" || $magic[1] !== "\x8b") {
            throw new \RuntimeException(sprintf(
                'Backup %s does not start with the gzip magic bytes; the object is likely corrupt.',
                $backup->path,
            ));
        }
    }
}
