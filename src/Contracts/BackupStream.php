<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

/**
 * Chunked, non-blocking stream abstraction for the streaming backup pipeline.
 *
 * Unlike PSR-7 StreamInterface, this contract models a live process pipe:
 * - read() is non-blocking and may return '' when no data is currently
 *   available even though the stream is not yet at EOF.
 * - close() is expected to validate the underlying source (e.g. check the
 *   child process exit code) and throw if the stream ended abnormally.
 */
interface BackupStream
{
    /**
     * Read up to $length bytes.
     *
     * @return string|null '' when no data is currently available,
     *                     null when the stream is at EOF.
     */
    public function read(int $length = 65536): ?string;

    public function isEof(): bool;

    /**
     * Release the underlying resource and validate its terminal state.
     */
    public function close(): void;
}
