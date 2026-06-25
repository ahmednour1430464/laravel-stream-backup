<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Streams\ChecksumStream;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory BackupStream that emits a fixed payload in chunks.
 */
final class InMemoryBackupStream implements BackupStream
{
    private int $offset = 0;

    private bool $closed = false;

    /**
     * @param  array<int, string>  $chunks
     */
    public function __construct(private readonly array $chunks) {}

    public function read(int $length = 65536): ?string
    {
        if ($this->offset >= count($this->chunks)) {
            return null;
        }

        return $this->chunks[$this->offset++];
    }

    public function isEof(): bool
    {
        return $this->offset >= count($this->chunks);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function wasClosed(): bool
    {
        return $this->closed;
    }
}

final class ChecksumStreamTest extends TestCase
{
    public function test_checksum_matches_direct_sha256_of_full_payload(): void
    {
        $chunks = ['hello ', 'world', '!', ' goodbye'];
        $payload = implode('', $chunks);
        $inner = new InMemoryBackupStream($chunks);

        $tee = new ChecksumStream($inner);

        $buffer = '';
        while (($chunk = $tee->read()) !== null) {
            $buffer .= $chunk;
        }
        $tee->close();

        self::assertSame($payload, $buffer);
        self::assertSame(hash('sha256', $payload), $tee->checksum());
        self::assertTrue($inner->wasClosed());
    }

    public function test_empty_chunks_do_not_break_hash(): void
    {
        $inner = new InMemoryBackupStream(['', 'abc', '', 'def']);
        $tee = new ChecksumStream($inner);

        while (($chunk = $tee->read()) !== null) {
            // drain
        }
        $tee->close();

        self::assertSame(hash('sha256', 'abcdef'), $tee->checksum());
    }

    public function test_close_is_idempotent(): void
    {
        $inner = new InMemoryBackupStream(['payload']);
        $tee = new ChecksumStream($inner);

        while (($chunk = $tee->read()) !== null) {
            // drain
        }

        $first = $tee->checksum();
        $tee->close();
        $tee->close();

        self::assertSame($first, $tee->checksum());
        self::assertSame(hash('sha256', 'payload'), $first);
    }

    public function test_checksum_can_be_requested_before_close(): void
    {
        $inner = new InMemoryBackupStream(['one', 'two']);
        $tee = new ChecksumStream($inner);

        while (($chunk = $tee->read()) !== null) {
            // drain
        }

        $early = $tee->checksum();
        $tee->close();

        self::assertSame(hash('sha256', 'onetwo'), $early);
    }
}
