<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Atomic concurrency cap for backup jobs using Laravel cache locks + an
 * integer counter. Prevents dozens of simultaneous mysqldump processes
 * when many tenants are queued at once.
 *
 * acquire() MUST be paired with release() in a finally block.
 */
final class BackupSemaphore
{
    private const LOCK_KEY = 'stream-backup:semaphore:lock';

    private const COUNTER_KEY = 'stream-backup:semaphore:count';

    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxConcurrent,
        private readonly int $lockTtl = 10,
    ) {}

    public function acquire(): bool
    {
        /** @var Repository&LockProvider $cache */
        $cache = $this->cache;
        $lock = $cache->lock(self::LOCK_KEY, $this->lockTtl);

        return (bool) $lock->block(5, function (): bool {
            $active = (int) ($this->cache->get(self::COUNTER_KEY) ?? 0);

            if ($active >= $this->maxConcurrent) {
                return false;
            }

            $this->cache->increment(self::COUNTER_KEY);

            return true;
        });
    }

    public function release(): void
    {
        /** @var Repository&LockProvider $cache */
        $cache = $this->cache;
        $lock = $cache->lock(self::LOCK_KEY, $this->lockTtl);

        $lock->block(5, function (): void {
            $active = (int) ($this->cache->get(self::COUNTER_KEY) ?? 0);
            if ($active <= 0) {
                $this->cache->forget(self::COUNTER_KEY);

                return;
            }
            $this->cache->decrement(self::COUNTER_KEY);
        });
    }

    public function active(): int
    {
        return (int) ($this->cache->get(self::COUNTER_KEY) ?? 0);
    }
}
