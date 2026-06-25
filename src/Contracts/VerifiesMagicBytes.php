<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\Models\Backup;

/**
 * Optional interface for encryption drivers that support post-upload
 * magic byte verification.
 */
interface VerifiesMagicBytes
{
    /**
     * The number of bytes required from the beginning of the file for verification.
     */
    public function magicBytesLength(): int;

    /**
     * Verify the magic bytes of the uploaded backup.
     *
     * @param  string  $magic  The first magicBytesLength() bytes of the object.
     * @param  Backup  $backup  The backup model being verified.
     *
     * @throws \RuntimeException If the magic bytes are invalid.
     */
    public function verifyMagicBytes(string $magic, Backup $backup): void;
}
