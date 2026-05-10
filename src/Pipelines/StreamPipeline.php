<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Pipelines;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\ChecksumStream;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use Ahmednour\StreamBackup\Uploaders\MultipartSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Non-blocking streaming pipeline:
 *
 *   mysqldump stdout -> pigz stdin -> pigz stdout -> S3 multipart upload
 *
 * Key design choices (ported from the hardened backup.php prototype):
 *
 * 1. Non-blocking pipes + stream_select: no process ever blocks waiting
 *    for another, which eliminates the classic 3-process deadlock.
 *
 * 2. Write-side stream_select: pigz stdin is added to the $write array
 *    only when there is pending data, letting the kernel notify us when
 *    the pipe is writable instead of polling with usleep(1_000).
 *
 * 3. php://temp part buffer: spills to disk after 2 MB, passed directly
 *    to uploadPart as a stream resource so no 32 MB string copy.
 *
 * 4. Exit-code check BEFORE completeMultipartUpload: otherwise a
 *    mid-stream mysqldump failure would produce a completed-but-corrupt
 *    gzip on S3 that we can no longer abort.
 */
final class StreamPipeline
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly CompressionDriver $compression,
        private readonly UploadDriver $uploader,
        private readonly Config $config,
    ) {
    }

    public function run(BackupContext $context, BackupMetadata $metadata): UploadResult
    {
        $readChunk = (int) $this->config->get('stream-backup.read_chunk', 64 * 1024);
        $partSize  = (int) $this->config->get('stream-backup.multipart.part_size', 32 * 1024 * 1024);

        // 1. Start mysqldump and wrap its stdout as a BackupStream.
        $dumpStream = $this->dumper->dump($context);
        if (! $dumpStream instanceof ProcessBackupStream) {
            throw new PipelineException('MySQLDumper must return a ProcessBackupStream.');
        }
        [$dumpProc, $dumpStdout, $dumpStderr] = $this->extractPipes($dumpStream);

        // 2. Start pigz.
        [$pigzProc, $pigzStdin, $pigzStdout, $pigzStderr] = $this->spawnCompressor();

        // 3. Create a ProcessBackupStream wrapper for pigz so close() can
        //    validate its exit code, and tee it through a ChecksumStream
        //    so the SHA-256 is computed during upload.
        $pigzBackupStream = new ProcessBackupStream($pigzProc, $pigzStdout, $pigzStderr, 'pigz');
        $compressedStream = new ChecksumStream($pigzBackupStream);

        // 4. Initiate the multipart upload (persists UploadId to the backups row).
        $session = $this->uploader->initiate($metadata);

        // 5. Allocate the streaming part buffer once.
        $partBuffer = fopen('php://temp', 'r+b');
        $bufferSize = 0;
        $partNumber = 1;

        // 6. Pipeline state.
        $pendingChunk    = '';   // bytes waiting to be written into pigz stdin
        $dumpDone        = false; // mysqldump stdout closed
        $pigzInputClosed = false; // pigz stdin closed (EOF signalled)
        $pigzDone        = false; // pigz stdout closed

        $startedAt = microtime(true);

        try {
            while (true) {
                $read  = [];
                $write = [];
                $except = null;

                // Read from mysqldump only when we have room to buffer.
                if (! $dumpDone && $pendingChunk === '') {
                    $read[] = $dumpStdout;
                }
                if (! $pigzDone) {
                    $read[] = $pigzStdout;
                }

                // Write side: only register pigz stdin when there's data queued.
                if ($pendingChunk !== '') {
                    $write[] = $pigzStdin;
                }

                if ($read === [] && $write === []) {
                    break;
                }

                $changed = @stream_select($read, $write, $except, 0, 200_000);

                if ($changed === false) {
                    // EINTR from signal handlers is normal; retry.
                    $err = error_get_last();
                    if ($err !== null && isset($err['message']) && str_contains($err['message'], 'Interrupted system call')) {
                        continue;
                    }
                    throw new PipelineException('stream_select failed.');
                }

                // ---- mysqldump stdout ----
                if (! $dumpDone && in_array($dumpStdout, $read, true)) {
                    $chunk = $dumpStream->read($readChunk);

                    if ($chunk === null) {
                        $dumpDone = true;
                    } elseif ($chunk !== '') {
                        $pendingChunk .= $chunk;
                    }
                }

                // ---- pigz stdin (write side) ----
                if ($pendingChunk !== '' && in_array($pigzStdin, $write, true)) {
                    $written = @fwrite($pigzStdin, $pendingChunk);
                    if ($written === false) {
                        throw new PipelineException('Failed to write to pigz stdin.');
                    }
                    if ($written > 0) {
                        $pendingChunk = substr($pendingChunk, $written);
                    }
                }

                // If the dump is fully drained AND the pending buffer has been
                // flushed, signal EOF to pigz by closing its stdin.
                if ($dumpDone && $pendingChunk === '' && ! $pigzInputClosed) {
                    @fclose($pigzStdin);
                    $pigzInputClosed = true;
                }

                // ---- pigz stdout ----
                if (! $pigzDone && in_array($pigzStdout, $read, true)) {
                    $compressed = $compressedStream->read($readChunk);

                    if ($compressed === null) {
                        $pigzDone = true;
                    } elseif ($compressed !== '') {
                        fwrite($partBuffer, $compressed);
                        $bufferSize += strlen($compressed);

                        if ($bufferSize >= $partSize) {
                            $this->flushPart($session, $partBuffer, $bufferSize, $partNumber);
                            $partNumber++;
                            $bufferSize = 0;
                        }
                    }
                }
            }

            // Flush the (smaller) tail part.
            if ($bufferSize > 0) {
                $this->flushPart($session, $partBuffer, $bufferSize, $partNumber);
            }

            // Validate BOTH processes BEFORE completing the upload so a
            // mid-stream failure never produces a completed object.
            $dumpStream->close();       // throws on non-zero exit / stderr errors
            $compressedStream->close(); // closes pigz stream and validates pigz

            $result = $this->uploader->complete($session);

            $duration = microtime(true) - $startedAt;

            return new UploadResult(
                bucket:          $result->bucket,
                key:             $result->key,
                sizeBytes:       $result->sizeBytes,
                partCount:       $result->partCount,
                durationSeconds: $duration,
                checksum:        $compressedStream->checksum(),
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup: abort multipart and terminate processes.
            $this->uploader->abort($session);
            $this->killProcess($dumpProc);
            $this->killProcess($pigzProc);
            $this->closeIfOpen($partBuffer);
            throw $e instanceof PipelineException ? $e : new PipelineException($e->getMessage(), 0, $e);
        } finally {
            $this->closeIfOpen($partBuffer);
        }
    }

    /**
     * @param resource $buffer
     */
    private function flushPart(MultipartSession $session, $buffer, int $size, int $partNumber): void
    {
        rewind($buffer);

        $this->uploader->uploadPart($session, $partNumber, $buffer, $size);

        // Reuse the same stream instead of re-opening: O(1), no allocation.
        ftruncate($buffer, 0);
        rewind($buffer);
    }

    /**
     * Reach into ProcessBackupStream to recover the raw pipes. The Pipeline
     * needs them for stream_select; the stream itself only exposes a
     * non-blocking read API.
     *
     * @return array{0: resource, 1: resource, 2: resource}
     */
    private function extractPipes(ProcessBackupStream $stream): array
    {
        $ref = new \ReflectionClass($stream);

        $process = $ref->getProperty('process'); $process->setAccessible(true);
        $stdout  = $ref->getProperty('stdout');  $stdout->setAccessible(true);
        $stderr  = $ref->getProperty('stderr');  $stderr->setAccessible(true);

        return [
            $process->getValue($stream),
            $stdout->getValue($stream),
            $stderr->getValue($stream),
        ];
    }

    /**
     * @return array{0: resource, 1: resource, 2: resource, 3: resource}
     */
    private function spawnCompressor(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($this->compression->buildCommand(), $descriptors, $pipes);

        if (! is_resource($proc)) {
            throw new PipelineException('Failed to start compression process.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes[0], $pipes[1], $pipes[2]];
    }

    /**
     * @param resource|null $proc
     */
    private function killProcess($proc): void
    {
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }

    /**
     * @param mixed $handle
     */
    private function closeIfOpen($handle): void
    {
        if (is_resource($handle)) {
            @fclose($handle);
        }
    }
}
