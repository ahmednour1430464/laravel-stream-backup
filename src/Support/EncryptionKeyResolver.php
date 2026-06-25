<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves the raw binary encryption key from environment or a key file.
 *
 * Security properties:
 *  - The key is NEVER logged (not even partially).
 *  - The key is NEVER passed as a CLI argument.
 *  - The key is returned to the caller immediately and not cached as state.
 *  - The caller (EncryptionStream) is responsible for wiping the key from
 *    memory via sodium_memzero() or equivalent in close().
 *
 * Resolution order:
 *   1. STREAM_BACKUP_ENCRYPTION_KEY  — base64-encoded raw bytes (env var)
 *   2. STREAM_BACKUP_ENCRYPTION_KEY_FILE — path to a file with raw binary key
 *   3. InvalidConfigException if neither is set and driver requires a key
 */
final class EncryptionKeyResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * Return the raw binary encryption key for the given driver.
     *
     * Returns an empty string when the driver requires no key (keyLength() === 0).
     *
     * @throws InvalidConfigException if the key is absent, malformed, or the wrong length
     */
    public function resolve(EncryptionDriver $driver): string
    {
        if ($driver->keyLength() === 0) {
            return '';
        }

        // --- 1. Base64-encoded env var ---
        $b64 = (string) $this->config->get('stream-backup.encryption.key', '');

        if ($b64 !== '') {
            $raw = base64_decode($b64, strict: true);

            if ($raw === false) {
                throw new InvalidConfigException(
                    'STREAM_BACKUP_ENCRYPTION_KEY is not valid strict base64. '.
                    'Generate a key: php -r "echo base64_encode(random_bytes(32));"'
                );
            }

            $this->assertKeyLength($raw, $driver);

            return $raw;
        }

        // --- 2. Raw binary key file ---
        $file = (string) $this->config->get('stream-backup.encryption.key_file', '');

        if ($file !== '') {
            if (! is_file($file)) {
                throw new InvalidConfigException(
                    "Encryption key file '{$file}' does not exist."
                );
            }

            if (! is_readable($file)) {
                throw new InvalidConfigException(
                    "Encryption key file '{$file}' is not readable."
                );
            }

            $raw = (string) file_get_contents($file);
            $this->assertKeyLength($raw, $driver);

            return $raw;
        }

        throw new InvalidConfigException(
            "Encryption driver '{$driver->name()}' is active but no key is configured. ".
            'Set STREAM_BACKUP_ENCRYPTION_KEY (base64) or STREAM_BACKUP_ENCRYPTION_KEY_FILE.'
        );
    }

    private function assertKeyLength(string $key, EncryptionDriver $driver): void
    {
        if (strlen($key) !== $driver->keyLength()) {
            throw new InvalidConfigException(sprintf(
                "Encryption driver '%s' requires a %d-byte key; resolved key is %d bytes. ".
                'Generate a valid key: php -r "echo base64_encode(random_bytes(%d));"',
                $driver->name(),
                $driver->keyLength(),
                strlen($key),
                $driver->keyLength(),
            ));
        }
    }
}
