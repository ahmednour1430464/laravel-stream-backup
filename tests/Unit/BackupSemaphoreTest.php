<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Support\BackupSemaphore;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository;

final class BackupSemaphoreTest extends TestCase
{
    private function makeSemaphore(int $max): BackupSemaphore
    {
        \assert($this->app !== null);

        return new BackupSemaphore(
            cache: $this->app->make(Repository::class),
            maxConcurrent: $max,
        );
    }

    public function test_acquire_succeeds_under_limit(): void
    {
        $semaphore = $this->makeSemaphore(2);

        self::assertTrue($semaphore->acquire());
        self::assertSame(1, $semaphore->active());

        self::assertTrue($semaphore->acquire());
        self::assertSame(2, $semaphore->active());
    }

    public function test_acquire_fails_over_limit(): void
    {
        $semaphore = $this->makeSemaphore(1);

        self::assertTrue($semaphore->acquire());
        self::assertFalse($semaphore->acquire());
        self::assertSame(1, $semaphore->active());
    }

    public function test_release_decrements_counter(): void
    {
        $semaphore = $this->makeSemaphore(2);

        $semaphore->acquire();
        $semaphore->acquire();
        self::assertSame(2, $semaphore->active());

        $semaphore->release();
        self::assertSame(1, $semaphore->active());

        $semaphore->release();
        self::assertSame(0, $semaphore->active());
    }

    public function test_release_allows_new_acquire_after_hitting_limit(): void
    {
        $semaphore = $this->makeSemaphore(1);

        self::assertTrue($semaphore->acquire());
        self::assertFalse($semaphore->acquire());

        $semaphore->release();

        self::assertTrue($semaphore->acquire());
    }

    public function test_release_on_empty_counter_is_safe(): void
    {
        $semaphore = $this->makeSemaphore(2);

        $semaphore->release();
        $semaphore->release();

        self::assertSame(0, $semaphore->active());
    }
}
