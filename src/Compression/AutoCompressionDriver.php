<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Compression;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Auto-detecting compression driver: prefers pigz (parallel gzip) for
 * multi-core performance, but gracefully falls back to gzip — which is
 * universally available on every Linux and macOS system — when pigz is
 * not installed.
 *
 * The resolved driver is cached after the first call so detection only
 * runs once per process.
 */
final class AutoCompressionDriver implements CompressionDriver
{
    private ?CompressionDriver $resolved = null;

    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly int $level = 4,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function buildCommand(): array
    {
        return $this->resolve()->buildCommand();
    }

    public function buildDecompressCommand(): array
    {
        return $this->resolve()->buildDecompressCommand();
    }

    public function name(): string
    {
        return $this->resolve()->name();
    }

    /**
     * Detect pigz availability. Fall back to gzip with a log notice.
     */
    private function resolve(): CompressionDriver
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->binaryExists('pigz')) {
            $this->resolved = new PigzDriver($this->locator, $this->level);

            return $this->resolved;
        }

        $this->logger->notice(
            'stream-backup: pigz not found, falling back to gzip. '
            .'Install pigz for 3–4× faster parallel compression: '
            .'apt-get install pigz / yum install pigz / brew install pigz'
        );

        $this->resolved = new GzipDriver($this->locator, $this->level);

        return $this->resolved;
    }

    /**
     * Quick probe: does the binary exist on the system?
     *
     * Uses `which` to avoid side effects. Unlike BinaryLocator::locate(),
     * this does NOT throw on failure — it returns a boolean so the
     * auto-driver can decide gracefully.
     */
    private function binaryExists(string $name): bool
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open(['which', $name], $descriptors, $pipes);
        if (! is_resource($proc)) {
            return false;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $exit = proc_close($proc);

        if ($exit !== 0) {
            return false;
        }

        $path = trim($stdout);

        return $path !== '' && is_executable($path);
    }
}
