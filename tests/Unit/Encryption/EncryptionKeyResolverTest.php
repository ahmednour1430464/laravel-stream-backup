<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Encryption;

use Ahmednour\StreamBackup\Encryption\NullEncryptionDriver;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class EncryptionKeyResolverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function makeResolver(array $config = []): EncryptionKeyResolver
    {
        $repo = new Repository(['stream-backup' => ['encryption' => $config]]);

        return new EncryptionKeyResolver($repo);
    }

    public function test_returns_empty_string_for_null_driver(): void
    {
        $resolver = $this->makeResolver([]);
        $key = $resolver->resolve(new NullEncryptionDriver);
        self::assertSame('', $key);
    }

    public function test_resolves_key_from_base64_env(): void
    {
        $raw = random_bytes(32);
        $resolver = $this->makeResolver(['key' => base64_encode($raw)]);
        $driver = new OpenSslAes256GcmDriver;

        $resolved = $resolver->resolve($driver);
        self::assertSame($raw, $resolved);
    }

    public function test_resolves_key_from_file(): void
    {
        $raw = random_bytes(32);
        $file = tempnam(sys_get_temp_dir(), 'backup_key_');
        file_put_contents($file, $raw);

        try {
            $resolver = $this->makeResolver(['key_file' => $file]);
            $driver = new OpenSslAes256GcmDriver;
            $resolved = $resolver->resolve($driver);
            self::assertSame($raw, $resolved);
        } finally {
            @unlink($file);
        }
    }

    public function test_env_key_takes_precedence_over_key_file(): void
    {
        $envKey = random_bytes(32);
        $fileKey = random_bytes(32);
        $file = tempnam(sys_get_temp_dir(), 'backup_key_');
        file_put_contents($file, $fileKey);

        try {
            $resolver = $this->makeResolver([
                'key' => base64_encode($envKey),
                'key_file' => $file,
            ]);
            $resolved = $resolver->resolve(new OpenSslAes256GcmDriver);
            self::assertSame($envKey, $resolved);
        } finally {
            @unlink($file);
        }
    }

    public function test_throws_when_neither_key_nor_file_is_set(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/no key is configured/');

        $this->makeResolver([])->resolve(new OpenSslAes256GcmDriver);
    }

    public function test_throws_on_invalid_base64(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/not valid strict base64/');

        $this->makeResolver(['key' => 'not-valid-base64!!!'])->resolve(new OpenSslAes256GcmDriver);
    }

    public function test_throws_on_wrong_key_length(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/requires a 32-byte key/');

        // 16 bytes instead of 32
        $this->makeResolver(['key' => base64_encode(random_bytes(16))])->resolve(new OpenSslAes256GcmDriver);
    }

    public function test_throws_when_key_file_missing(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->makeResolver(['key_file' => '/nonexistent/path/to/key'])->resolve(new OpenSslAes256GcmDriver);
    }
}
