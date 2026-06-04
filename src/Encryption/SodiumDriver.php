<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Streams\SodiumEncryptionStream;

/**
 * XChaCha20-Poly1305 stream encryption driver via PHP's ext-sodium.
 *
 * Uses the sodium_crypto_secretstream_xchacha20poly1305_* API, which
 * manages nonce advancement automatically via an internal state machine.
 * No manual IV/counter bookkeeping is required.
 *
 * The driver is stateless — all per-backup mutable state lives inside
 * SodiumEncryptionStream so concurrent backups are independent.
 *
 * Wire format (handled by SodiumEncryptionStream):
 *   Header:    [0x02 (version byte)][24-byte secretstream push header]
 *   Chunks:    [4-byte BE len][sodium ciphertext (plaintext_len + 17 bytes overhead)]
 *   Last chunk: TAG_FINAL embedded in ciphertext (no extra bytes needed)
 *
 * Requirements: ext-sodium (bundled since PHP 7.2, available everywhere).
 *
 * Generate a key:
 *   php -r "echo base64_encode(random_bytes(32));"
 */
final class SodiumDriver implements EncryptionDriver
{
    public function spawn(BackupStream $inner, string $key): BackupStream
    {
        if (! extension_loaded('sodium')) {
            throw new InvalidConfigException(
                "Encryption driver 'sodium' requires ext-sodium, which is not loaded."
            );
        }

        if (strlen($key) !== $this->keyLength()) {
            throw new InvalidConfigException(sprintf(
                'Encryption driver "%s" requires a %d-byte key; got %d bytes. ' .
                'Generate a valid key: php -r "echo base64_encode(random_bytes(%d));"',
                $this->name(),
                $this->keyLength(),
                strlen($key),
                $this->keyLength(),
            ));
        }

        return new SodiumEncryptionStream($inner, $key);
    }

    public function name(): string
    {
        return 'sodium';
    }

    public function keyLength(): int
    {
        return SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES; // 32
    }
}
