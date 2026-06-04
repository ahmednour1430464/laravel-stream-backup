<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\SodiumDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Streams\SodiumEncryptionStream;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory BackupStream for testing (local to this file).
 */
final class SodiumFixedChunkStream implements BackupStream
{
    private int $offset = 0;

    /** @param array<int, string> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function read(int $length = 65536): ?string
    {
        if ($this->offset >= count($this->chunks)) {
            return null;
        }
        return $this->chunks[$this->offset++];
    }

    public function isEof(): bool { return $this->offset >= count($this->chunks); }
    public function close(): void {}
}

/**
 * Decrypts a SodiumEncryptionStream output buffer for round-trip tests.
 * Mirrors the wire format documented on SodiumEncryptionStream.
 */
function sodiumDecrypt(string $ciphertext, string $key): string
{
    $pos = 0;

    $version = ord($ciphertext[$pos++]);
    assert($version === 0x02, "Expected version 0x02, got {$version}");

    $header  = substr($ciphertext, $pos, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
    $pos    += SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

    $plaintext = '';

    while ($pos < strlen($ciphertext)) {
        $len  = unpack('N', substr($ciphertext, $pos, 4))[1];
        $pos += 4;

        $chunk  = substr($ciphertext, $pos, $len);
        $pos   += $len;

        [$plain, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
        assert($plain !== false, 'sodium_crypto_secretstream_xchacha20poly1305_pull failed');

        $plaintext .= $plain;

        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            break;
        }
    }

    return $plaintext;
}

final class SodiumDriverTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }
    }

    private function validKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    // ---- Driver contract ----

    public function test_name_returns_sodium(): void
    {
        self::assertSame('sodium', (new SodiumDriver())->name());
    }

    public function test_key_length_returns_32(): void
    {
        self::assertSame(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            (new SodiumDriver())->keyLength()
        );
    }

    public function test_spawn_returns_sodium_encryption_stream(): void
    {
        $inner  = new SodiumFixedChunkStream(['hello']);
        $stream = (new SodiumDriver())->spawn($inner, $this->validKey());
        self::assertInstanceOf(SodiumEncryptionStream::class, $stream);
    }

    public function test_spawn_throws_on_wrong_key_length(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/requires a 32-byte key/');

        (new SodiumDriver())->spawn(new SodiumFixedChunkStream([]), random_bytes(16));
    }

    // ---- Round-trip correctness ----

    public function test_round_trip_single_chunk(): void
    {
        $key       = $this->validKey();
        $plaintext = 'hello sodium world!';
        $stream    = (new SodiumDriver())->spawn(new SodiumFixedChunkStream([$plaintext]), $key);

        $encrypted = $this->drainStream($stream);
        $stream->close();

        self::assertNotSame($plaintext, $encrypted);
        self::assertSame($plaintext, sodiumDecrypt($encrypted, $key));
    }

    public function test_round_trip_multiple_chunks(): void
    {
        $key    = $this->validKey();
        $chunks = ['alpha', 'beta', 'gamma'];
        $stream = (new SodiumDriver())->spawn(new SodiumFixedChunkStream($chunks), $key);

        $encrypted = $this->drainStream($stream);
        $stream->close();

        self::assertSame(implode('', $chunks), sodiumDecrypt($encrypted, $key));
    }

    public function test_file_header_version_byte_is_0x02(): void
    {
        $key    = $this->validKey();
        $stream = (new SodiumDriver())->spawn(new SodiumFixedChunkStream(['x']), $key);

        $output = $this->drainStream($stream);
        $stream->close();

        self::assertSame(0x02, ord($output[0]));
    }

    public function test_two_encryptions_produce_different_ciphertexts(): void
    {
        $key  = $this->validKey();
        $data = 'same plaintext';

        $enc1 = $this->drainStream((new SodiumDriver())->spawn(new SodiumFixedChunkStream([$data]), $key));
        $enc2 = $this->drainStream((new SodiumDriver())->spawn(new SodiumFixedChunkStream([$data]), $key));

        self::assertNotSame($enc1, $enc2, 'Each backup must use a different random header (different ciphertext).');
    }

    public function test_tampered_ciphertext_fails_pull(): void
    {
        $key    = $this->validKey();
        $stream = (new SodiumDriver())->spawn(new SodiumFixedChunkStream(['secret']), $key);

        $output = $this->drainStream($stream);
        $stream->close();

        // Flip a bit deep in the ciphertext region
        $headerSize = 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $flipAt     = $headerSize + 4 + 5; // skip version+header+length, then flip byte 5 of first chunk

        $tampered        = $output;
        $tampered[$flipAt] = chr(ord($tampered[$flipAt]) ^ 0xFF);

        // sodium_pull returns false (not an exception) on authentication failure
        $pos     = 1; // skip version byte
        $header  = substr($tampered, $pos, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $pos    += SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $state   = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $len     = unpack('N', substr($tampered, $pos, 4))[1]; $pos += 4;
        $chunk   = substr($tampered, $pos, $len);

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
        self::assertFalse($result, 'Tampered ciphertext must return false from sodium_pull (auth failure).');
    }


    public function test_empty_stream_produces_header_and_final_chunk_only(): void
    {
        $key    = $this->validKey();
        $stream = (new SodiumDriver())->spawn(new SodiumFixedChunkStream([]), $key);

        $output = $this->drainStream($stream);
        $stream->close();

        // 1 version + 24 header bytes + 4 chunk-len + ABYTES (17 bytes final chunk) = 46
        $expected = 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 4
            + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        self::assertSame($expected, strlen($output));
    }

    // ---- Helper ----

    private function drainStream(BackupStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read(128)) !== null) {
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
