<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Encryption\NullEncryptionDriver;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Encryption\SodiumDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class EncryptionFactoryTest extends TestCase
{
    private function makeFactory(string $driver = 'none'): EncryptionFactory
    {
        $app = new Container;
        $config = new Repository(['stream-backup' => ['encryption' => ['driver' => $driver]]]);

        // Register bindings for built-in drivers
        $app->bind(NullEncryptionDriver::class, fn () => new NullEncryptionDriver);
        $app->bind(OpenSslAes256GcmDriver::class, fn () => new OpenSslAes256GcmDriver);
        $app->bind(SodiumDriver::class, fn () => new SodiumDriver);

        return new EncryptionFactory($app, $config);
    }

    public function test_make_none_returns_null_driver(): void
    {
        $driver = $this->makeFactory('none')->make();
        self::assertInstanceOf(NullEncryptionDriver::class, $driver);
    }

    public function test_make_openssl_returns_openssl_driver(): void
    {
        if (! extension_loaded('openssl')) {
            $this->markTestSkipped('ext-openssl not available.');
        }

        $driver = $this->makeFactory('openssl-aes-256-gcm')->make();
        self::assertInstanceOf(OpenSslAes256GcmDriver::class, $driver);
    }

    public function test_make_sodium_returns_sodium_driver(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        $driver = $this->makeFactory('sodium')->make();
        self::assertInstanceOf(SodiumDriver::class, $driver);
    }

    public function test_make_unknown_throws_invalid_config_exception(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches("/Unknown encryption driver 'foobar'/");

        $this->makeFactory('foobar')->make();
    }

    public function test_extend_registers_custom_driver(): void
    {
        $factory = $this->makeFactory('none');

        $customDriver = new class implements EncryptionDriver
        {
            public function spawn(BackupStream $inner, string $key): BackupStream
            {
                return $inner;
            }

            public function spawnDecrypt(BackupStream $inner, string $key): BackupStream
            {
                return $inner;
            }

            public function name(): string
            {
                return 'custom';
            }

            public function keyLength(): int
            {
                return 0;
            }
        };

        $factory->extend('custom', fn () => $customDriver);

        $resolved = $factory->make('custom');
        self::assertSame($customDriver, $resolved);
    }

    public function test_extend_custom_driver_takes_precedence_over_built_in_name(): void
    {
        $factory = $this->makeFactory('none');

        $override = new class implements EncryptionDriver
        {
            public function spawn(BackupStream $inner, string $key): BackupStream
            {
                return $inner;
            }

            public function spawnDecrypt(BackupStream $inner, string $key): BackupStream
            {
                return $inner;
            }

            public function name(): string
            {
                return 'none';
            }

            public function keyLength(): int
            {
                return 0;
            }
        };

        $factory->extend('none', fn () => $override);

        $resolved = $factory->make('none');
        self::assertSame($override, $resolved);
    }

    public function test_make_with_null_uses_config_driver(): void
    {
        $driver = $this->makeFactory('none')->make(null);
        self::assertInstanceOf(NullEncryptionDriver::class, $driver);
    }
}
