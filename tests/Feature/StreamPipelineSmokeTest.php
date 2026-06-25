<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * End-to-end smoke test for the StreamPipeline.
 *
 * This test is skipped unless the host actually provides mysqldump and pigz
 * on PATH, plus a writable test MySQL database described by env vars:
 *
 *   STREAM_BACKUP_TEST_HOST
 *   STREAM_BACKUP_TEST_PORT
 *   STREAM_BACKUP_TEST_USER
 *   STREAM_BACKUP_TEST_PASSWORD
 *   STREAM_BACKUP_TEST_DATABASE
 *
 * It intentionally does NOT hit S3. A MinIO / LocalStack endpoint can be
 * wired in by the consumer if they want full round-trip coverage.
 */
final class StreamPipelineSmokeTest extends TestCase
{
    public function test_mysqldump_and_pigz_are_available(): void
    {
        $this->skipUnlessBinariesAndDatabaseAvailable();

        // The real pipeline test is implementation-specific; at this stage we
        // only assert that the prerequisites the pipeline relies on actually
        // exist on the host so that wiring issues surface early in CI.
        self::assertNotFalse($this->locateBinary('mysqldump'));
        self::assertNotFalse($this->locateBinary('pigz'));
        self::assertNotEmpty(env('STREAM_BACKUP_TEST_DATABASE'));
    }

    private function skipUnlessBinariesAndDatabaseAvailable(): void
    {
        foreach (['mysqldump', 'pigz'] as $binary) {
            if ($this->locateBinary($binary) === false) {
                self::markTestSkipped("{$binary} is not available on PATH");
            }
        }

        foreach (['STREAM_BACKUP_TEST_HOST', 'STREAM_BACKUP_TEST_USER', 'STREAM_BACKUP_TEST_DATABASE'] as $envVar) {
            if (empty(env($envVar))) {
                self::markTestSkipped("{$envVar} is not configured for smoke test");
            }
        }
    }

    private function locateBinary(string $binary): string|false
    {
        $output = @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)));

        if ($output === false || $output === null || $output === '') {
            return false;
        }

        $path = trim($output);

        return $path === '' ? false : $path;
    }
}
