<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;

/**
 * Generic BackupStream wrapper around a PHP stream resource.
 *
 * Used by the Local and SFTP download drivers to present a file handle
 * (or php://temp spool) as a BackupStream for the restore pipeline.
 *
 * This is the download-side counterpart of S3DownloadStream, but agnostic
 * to the underlying source — it works with any seekable/readable resource.
 */
final class FileDownloadStream implements BackupStream
{
    /** @var resource */
    private $stream;

    private bool $eof    = false;
    private bool $closed = false;

    /**
     * @param resource $stream A valid, readable PHP stream resource
     */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('FileDownloadStream requires a valid stream resource.');
        }

        $this->stream = $stream;
    }

    public function read(int $length = 65536): ?string
    {
        if ($this->eof) {
            return null;
        }

        $data = @fread($this->stream, $length);

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
