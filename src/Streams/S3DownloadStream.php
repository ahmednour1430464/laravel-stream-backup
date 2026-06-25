<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;

/**
 * Wraps an S3 GetObject response body (a GuzzleHttp\Psr7\Stream backed
 * by a PHP resource) as a non-blocking BackupStream for the restore pipeline.
 *
 * The S3 SDK returns the response body as a Psr7 StreamInterface which
 * wraps a PHP stream resource. We extract the underlying resource and
 * set it to non-blocking mode so it is compatible with stream_select().
 */
final class S3DownloadStream implements BackupStream
{
    /** @var resource */
    private $stream;

    private bool $eof = false;

    private bool $closed = false;

    /**
     * @param  resource  $stream  The raw PHP stream resource from the S3 response body
     */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('S3DownloadStream requires a valid stream resource.');
        }

        $this->stream = $stream;

    }

    public function read(int $length = 65536): ?string
    {
        if ($this->eof) {
            return null;
        }

        $data = @fread($this->stream, max(1, $length));

        if ($data === false) {
            return '';
        }

        if ($data === '' && feof($this->stream)) {
            $this->eof = true;

            return null;
        }

        return $data;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }
}
