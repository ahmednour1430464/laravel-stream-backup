<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Compression\AutoCompressionDriver;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \Ahmednour\StreamBackup\Compression\AutoCompressionDriver
 */
final class AutoCompressionDriverTest extends TestCase
{
    /**
     * When pigz is available on the system the auto driver should
     * resolve to pigz (parallel gzip) for better performance.
     */
    public function test_resolves_to_pigz_when_available(): void
    {
        if ($this->binaryMissing('pigz')) {
            self::markTestSkipped('pigz is not installed on this system.');
        }

        $driver = new AutoCompressionDriver(new BinaryLocator(), 4, new NullLogger());

        self::assertSame('pigz', $driver->name());
        self::assertStringContainsString('pigz', $driver->buildCommand()[0]);
    }

    /**
     * When pigz is NOT available the auto driver should gracefully fall
     * back to gzip — which is universally installed — and log a notice.
     */
    public function test_falls_back_to_gzip_when_pigz_missing(): void
    {
        // Construct a locator that intentionally cannot find pigz by
        // pointing it at a non-existent path and using an empty PATH.
        // The auto driver's own `binaryExists()` probe uses `which`,
        // so we simulate a system without pigz by manipulating PATH.
        $originalPath = getenv('PATH');
        $tmpDir = sys_get_temp_dir() . '/stream-backup-test-' . getmypid();
        @mkdir($tmpDir, 0700, true);

        try {
            $gzipReal = trim((string) shell_exec('which gzip'));
            if ($gzipReal === '' || ! is_executable($gzipReal)) {
                self::markTestSkipped('gzip is not installed on this system.');
            }

            symlink($gzipReal, $tmpDir . '/gzip');

            // Restrict PATH so `which pigz` fails
            putenv("PATH={$tmpDir}");

            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())
                ->method('notice')
                ->with(self::stringContains('pigz not found'));

            $locator = new BinaryLocator();
            $driver  = new AutoCompressionDriver($locator, 4, $logger);

            self::assertSame('gzip', $driver->name());
            self::assertStringContainsString('gzip', $driver->buildCommand()[0]);
        } finally {
            putenv("PATH={$originalPath}");
            @unlink($tmpDir . '/gzip');
            @rmdir($tmpDir);
        }
    }

    /**
     * The resolved driver should be cached — calling name() twice
     * should not trigger a second `which` probe.
     */
    public function test_resolution_is_cached(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // If it falls back, notice should fire at most once
        $logger->expects(self::atMost(1))->method('notice');

        $driver = new AutoCompressionDriver(new BinaryLocator(), 4, $logger);

        $first  = $driver->name();
        $second = $driver->name();

        self::assertSame($first, $second);
    }

    /**
     * Decompression command should match the resolved driver.
     */
    public function test_decompress_command_matches_resolved_driver(): void
    {
        $driver = new AutoCompressionDriver(new BinaryLocator(), 4, new NullLogger());

        $name       = $driver->name();
        $decompCmd  = $driver->buildDecompressCommand();

        self::assertStringContainsString($name, $decompCmd[0]);
        self::assertContains('-d', $decompCmd);
        self::assertContains('-c', $decompCmd);
    }

    private function binaryMissing(string $name): bool
    {
        $result = trim((string) shell_exec("which {$name} 2>/dev/null"));

        return $result === '' || ! is_executable($result);
    }
}
