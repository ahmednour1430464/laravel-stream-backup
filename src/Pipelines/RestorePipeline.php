<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Pipelines;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Exceptions\BackupFileNotFoundException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Restore\SqlDumpParser;
use Ahmednour\StreamBackup\Restore\TableRestorer;
use Ahmednour\StreamBackup\Streams\S3DownloadStream;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Aws\S3\S3ClientInterface;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Streaming restore pipeline — the inverse of StreamPipeline.
 *
 *   S3 GetObject (stream) → decrypt (if encrypted) → decompress (pigz -d)
 *       → SqlDumpParser (line-by-line) → TableRestorer (transaction)
 *
 * Key design choices:
 *
 * 1. Streaming download: S3 GetObject body is consumed as a PHP stream,
 *    never buffered entirely in memory.
 *
 * 2. Non-blocking decompression: pigz -d is spawned as a child process;
 *    the encrypted/plain stream is piped into its stdin via stream_select()
 *    while decompressed SQL is read from stdout — same pattern as the
 *    backup pipeline.
 *
 * 3. The SqlDumpParser reads decompressed SQL line-by-line and extracts
 *    only the requested tables into bounded php://temp buffers.
 *
 * 4. The TableRestorer executes inside a single DB transaction with
 *    FK checks disabled.
 */
final class RestorePipeline
{
    public function __construct(
        private readonly CompressionDriver $compression,
        private readonly EncryptionFactory $encryptionFactory,
        private readonly EncryptionKeyResolver $keyResolver,
        private readonly SqlDumpParser $parser,
        private readonly TableRestorer $restorer,
        private readonly S3ClientInterface $s3,
        private readonly Config $config,
    ) {
    }

    public function run(RestoreContext $context, Backup $backup): RestoreResult
    {
        $startTime = microtime(true);
        $readChunk = (int) $this->config->get('stream-backup.read_chunk', 64 * 1024);
        $bucket    = $this->resolveBucket($backup);

        // 1. Verify the backup file exists on S3.
        $this->assertFileExists($bucket, $backup->path);

        // 2. Download the backup as a stream.
        $response     = $this->s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $backup->path,
        ]);
        $bodyResource = $this->extractStreamResource($response['Body']);
        $downloadStream = new S3DownloadStream($bodyResource);

        // 3. Decrypt if the backup was encrypted.
        $decryptedStream = $this->applyDecryption($downloadStream, $backup);

        // 4. Spawn decompressor (pigz -d -c) and pipe the stream through it.
        [$decompProc, $decompStdin, $decompStdout, $decompStderr] = $this->spawnDecompressor();

        try {
            // 5. Pipe the decrypted stream into the decompressor's stdin,
            //    while simultaneously reading decompressed SQL from stdout.
            //    Collect the full decompressed output into a temp stream
            //    that the SqlDumpParser can read line-by-line.
            $sqlStream = $this->pipeToDecompressor(
                $decryptedStream,
                $decompProc,
                $decompStdin,
                $decompStdout,
                $decompStderr,
                $readChunk,
            );

            // 6. Parse the decompressed SQL to extract requested table blocks.
            $tableBlocks = $this->parser->parse($sqlStream, $context->tables);

            // Close the sql stream now that parsing is complete.
            if (is_resource($sqlStream)) {
                fclose($sqlStream);
            }

            // 7. Restore the tables inside a transaction.
            return $this->restorer->restore($tableBlocks, $context->connectionName, $startTime);
        } catch (\Throwable $e) {
            $this->killProcess($decompProc);
            throw $e instanceof PipelineException ? $e : new PipelineException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply decryption if the backup was encrypted.
     */
    private function applyDecryption(BackupStream $stream, Backup $backup): BackupStream
    {
        $driverName = $backup->encryption_driver;

        if ($driverName === null || $driverName === '' || $driverName === 'none') {
            return $stream;
        }

        $driver = $this->encryptionFactory->make($driverName);
        $key    = $this->keyResolver->resolve($driver);

        return $driver->spawnDecrypt($stream, $key);
    }

    /**
     * Pipe the source stream into the decompressor and collect decompressed
     * output into a php://temp stream using stream_select().
     *
     * @return resource php://temp stream containing the full decompressed SQL
     */
    private function pipeToDecompressor(
        BackupStream $source,
        mixed $decompProc,
        mixed $decompStdin,
        mixed $decompStdout,
        mixed $decompStderr,
        int $readChunk,
    ): mixed {
        $output = fopen('php://temp', 'r+b');

        $pendingChunk    = '';
        $sourceDone      = false;
        $stdinClosed     = false;
        $stdoutDone      = false;
        $stderrBuffer    = '';

        while (true) {
            $read   = [];
            $write  = [];
            $except = null;

            if (! $sourceDone && $pendingChunk === '') {
                // We need data from source — but source is a BackupStream,
                // not a raw resource for stream_select. Read from it directly.
            }

            if (! $stdoutDone) {
                $read[] = $decompStdout;
            }

            if ($pendingChunk !== '' && ! $stdinClosed) {
                $write[] = $decompStdin;
            }

            // Also drain stderr.
            if (is_resource($decompStderr)) {
                $read[] = $decompStderr;
            }

            if ($read === [] && $write === []) {
                break;
            }

            // Read from the BackupStream (non-blocking) when we have no pending data.
            if (! $sourceDone && $pendingChunk === '') {
                $chunk = $source->read($readChunk);
                if ($chunk === null) {
                    $sourceDone = true;
                } elseif ($chunk !== '') {
                    $pendingChunk .= $chunk;
                }
            }

            $changed = @stream_select($read, $write, $except, 0, 200_000);

            if ($changed === false) {
                $err = error_get_last();
                if ($err !== null && isset($err['message']) && str_contains($err['message'], 'Interrupted system call')) {
                    continue;
                }
                throw new PipelineException('stream_select failed during decompression.');
            }

            // Write pending data to decompressor stdin.
            if ($pendingChunk !== '' && ! $stdinClosed && in_array($decompStdin, $write, true)) {
                $written = @fwrite($decompStdin, $pendingChunk);
                if ($written === false) {
                    throw new PipelineException('Failed to write to decompressor stdin.');
                }
                if ($written > 0) {
                    $pendingChunk = substr($pendingChunk, $written);
                }
            }

            // Close decompressor stdin once source is fully piped.
            if ($sourceDone && $pendingChunk === '' && ! $stdinClosed) {
                @fclose($decompStdin);
                $stdinClosed = true;
            }

            // Read decompressed output.
            if (! $stdoutDone && in_array($decompStdout, $read, true)) {
                $data = @fread($decompStdout, $readChunk);
                if ($data === false || ($data === '' && feof($decompStdout))) {
                    $stdoutDone = true;
                } elseif ($data !== '') {
                    fwrite($output, $data);
                }
            }

            // Drain stderr.
            if (is_resource($decompStderr) && in_array($decompStderr, $read, true)) {
                $errData = @fread($decompStderr, 8192);
                if ($errData !== false && $errData !== '') {
                    $stderrBuffer .= $errData;
                }
            }
        }

        // Close remaining pipes and validate exit code.
        if (is_resource($decompStdout)) {
            @fclose($decompStdout);
        }
        if (is_resource($decompStderr)) {
            @fclose($decompStderr);
        }

        $this->validateDecompressorExit($decompProc, $stderrBuffer);

        // Close the source stream (which may also close the decryption layer).
        $source->close();

        rewind($output);
        return $output;
    }

    /**
     * Spawn the decompression child process.
     *
     * @return array{0: resource, 1: resource, 2: resource, 3: resource}
     */
    private function spawnDecompressor(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($this->compression->buildDecompressCommand(), $descriptors, $pipes);

        if (! is_resource($proc)) {
            throw new PipelineException('Failed to start decompression process.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes[0], $pipes[1], $pipes[2]];
    }

    private function validateDecompressorExit(mixed $proc, string $stderrBuffer): void
    {
        $status = proc_get_status($proc);
        $waitStart = microtime(true);

        while ($status['running'] && microtime(true) - $waitStart < 5.0) {
            usleep(50_000);
            $status = proc_get_status($proc);
        }

        if ($status['running']) {
            proc_terminate($proc);
            proc_close($proc);
            throw new PipelineException('Decompression process timed out.');
        }

        $exitCode = $status['exitcode'];
        proc_close($proc);

        if ($exitCode !== 0) {
            throw new PipelineException(sprintf(
                'Decompression process exited with code %d. stderr: %s',
                $exitCode,
                trim($stderrBuffer),
            ));
        }
    }

    private function assertFileExists(string $bucket, ?string $path): void
    {
        if ($path === null || $path === '') {
            throw new BackupFileNotFoundException('Backup has no file path set.');
        }

        try {
            $this->s3->headObject([
                'Bucket' => $bucket,
                'Key'    => $path,
            ]);
        } catch (\Throwable $e) {
            throw new BackupFileNotFoundException(
                "Backup file '{$path}' not found in bucket '{$bucket}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    private function resolveBucket(Backup $backup): string
    {
        $configured = $this->config->get("filesystems.disks.{$backup->disk}.bucket");
        return is_string($configured) && $configured !== '' ? $configured : $backup->disk;
    }

    /**
     * Extract the underlying PHP stream resource from the S3 response body.
     *
     * The AWS SDK returns a GuzzleHttp\Psr7\Stream wrapping a PHP resource.
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
}
