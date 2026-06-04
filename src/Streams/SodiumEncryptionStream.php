<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;

/**
 * XChaCha20-Poly1305 streaming encryptor — BackupStream decorator.
 *
 * Uses libsodium's secretstream API which manages nonce advancement
 * automatically via an internal state machine. No manual IV tracking.
 *
 * Memory per iteration: one plaintext chunk + (plaintext_len + 17 bytes)
 * ciphertext; both freed at the end of each read() call.
 *
 * Wire format:
 *   [1 byte:   version = 0x02]
 *   [24 bytes: secretstream push header (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)]
 *   // for each plaintext chunk:
 *   [4 bytes BE: ciphertext length  (= plaintext_len + ABYTES = plaintext_len + 17)]
 *   [N bytes: sodium ciphertext (includes the 1-byte tag and 16-byte MAC)]
 *   // last chunk uses TAG_FINAL (embedded in ciphertext, no extra marker needed)
 *
 * Decryption pseudocode (for a future restore command):
 *   read 1 byte  → assert version == 0x02
 *   read 24 bytes → header
 *   state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(header, key)
 *   loop:
 *     read 4 bytes → len
 *     read len bytes → ciphertext
 *     [plaintext, tag] = sodium_crypto_secretstream_xchacha20poly1305_pull(state, ciphertext)
 *     if tag == TAG_FINAL: break
 *
 * Implementation note on sodium state:
 *   sodium_crypto_secretstream_xchacha20poly1305_init_push() returns [0 => state, 1 => header].
 *   The push() function mutates the state in-place via a C-level reference.
 *   Passing $this->state directly works because PHP's sodium binding receives
 *   the property's underlying zval by reference.
 */
final class SodiumEncryptionStream implements BackupStream
{
    private const VERSION = "\x02";

    /**
     * Opaque secretstream push state (52 bytes on PHP 8.x).
     * Mutated in-place by sodium_crypto_secretstream_xchacha20poly1305_push().
     * Typed as mixed because PHP's sodium binding returns a string that it
     * then treats as a C-level reference internally.
     */
    private mixed $state;

    private string $pending   = '';
    private bool   $innerDone = false;
    private bool   $eof       = false;
    private bool   $closed    = false;

    public function __construct(
        private readonly BackupStream $inner,
        private string $key, // mutable so sodium_memzero can wipe it
    ) {
        // init_push returns [0 => state, 1 => header (24 bytes)]
        $result        = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $this->state   = $result[0];
        $header        = $result[1]; // 24-byte nonce header
        $this->pending = self::VERSION . $header;
    }

    public function read(int $length = 65536): ?string
    {
        if ($this->eof) {
            return null;
        }

        // (A) Drain header bytes before any encryption work.
        if ($this->pending !== '') {
            $out           = $this->pending;
            $this->pending = '';
            return $out;
        }

        // (B) We already emitted the TAG_FINAL chunk last call; signal EOF.
        if ($this->innerDone) {
            $this->eof = true;
            return null;
        }

        $plain = $this->inner->read($length);

        // Non-blocking: no data ready yet.
        if ($plain === '') {
            return '';
        }

        if ($plain === null) {
            // Emit the final encrypted chunk with TAG_FINAL so the decoder can
            // verify the stream ended legitimately (not truncated by a crash).
            $this->innerDone = true;
            $ciphertext      = sodium_crypto_secretstream_xchacha20poly1305_push(
                $this->state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            return pack('N', strlen($ciphertext)) . $ciphertext;
        }

        $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $plain,
            '',
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
        );

        return pack('N', strlen($ciphertext)) . $ciphertext;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        // Wipe the key from PHP memory first — before any potential exception.
        sodium_memzero($this->key);

        $this->inner->close();
    }
}
