<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\NullEncryptionDriver;
use PHPUnit\Framework\TestCase;

final class NullEncryptionDriverTest extends TestCase
{
    public function test_spawn_returns_inner_stream_unchanged(): void
    {
        $inner  = $this->createMock(BackupStream::class);
        $driver = new NullEncryptionDriver();

        $result = $driver->spawn($inner, '');

        self::assertSame($inner, $result, 'NullEncryptionDriver must return the inner stream as-is.');
    }

    public function test_name_returns_none(): void
    {
        self::assertSame('none', (new NullEncryptionDriver())->name());
    }

    public function test_key_length_returns_zero(): void
    {
        self::assertSame(0, (new NullEncryptionDriver())->keyLength());
    }
}
