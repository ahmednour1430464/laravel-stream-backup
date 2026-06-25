<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use HashContext;

/**
 * SHA-256 tee over an inner BackupStream. Every byte that passes through
 * read() is fed into a running hash so the final checksum is available
 * after the stream is drained, without any additional memory cost.
 */
final class ChecksumStream implements BackupStream
{
    private HashContext $hash;

    private ?string $finalDigest = null;

    public function __construct(
        private readonly BackupStream $inner,
        string $algorithm = 'sha256',
    ) {
        $this->hash = hash_init($algorithm);
    }

    public function read(int $length = 65536): ?string
    {
        $chunk = $this->inner->read($length);

        if ($chunk !== null && $chunk !== '') {
            hash_update($this->hash, $chunk);
        }

        return $chunk;
    }

    public function isEof(): bool
    {
        return $this->inner->isEof();
    }

    public function close(): void
    {
        if ($this->finalDigest === null) {
            $this->finalDigest = hash_final($this->hash);
        }
        $this->inner->close();
    }

    /**
     * The hex digest. Safe to call after close(); if called before close()
     * it finalises the hash and further read() calls will NOT update it.
     */
    public function checksum(): string
    {
        if ($this->finalDigest === null) {
            $this->finalDigest = hash_final($this->hash);
        }

        return $this->finalDigest;
    }
}
