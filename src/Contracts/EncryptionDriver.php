<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

/**
 * Contract for stream encryption drivers.
 *
 * Each driver wraps an upstream BackupStream in a chunk-by-chunk
 * encryption layer, preserving the pipeline's O(1) memory model:
 * plaintext flows through in fixed-size reads; ciphertext is handed
 * back per-chunk without accumulating the full backup in memory.
 *
 * --- Extension (OCP) ---
 * Register third-party drivers via EncryptionFactory::extend():
 *
 *     $this->app->make(EncryptionFactory::class)
 *         ->extend('age', fn($app) => new AgeDriver(...));
 *
 * No existing file needs to change to add a new driver.
 *
 * --- Key contract ---
 * The raw binary key passed to spawn() MUST have exactly keyLength()
 * bytes. EncryptionKeyResolver validates this before calling spawn().
 * Key material MUST be wiped from memory in close().
 */
interface EncryptionDriver
{
    /**
     * Wrap $inner in an encrypted BackupStream.
     *
     * Implementations MUST:
     *  - Return $inner unchanged when no encryption is needed (NullEncryptionDriver).
     *  - Create a stateful stream decorator that encrypts data chunk-by-chunk.
     *  - Bound per-chunk memory to O(read_chunk_size) — no full-stream buffering.
     *  - Wipe key material from memory on close().
     *
     * @param  BackupStream  $inner  Upstream stream (e.g. pigz stdout)
     * @param  string  $key  Raw binary key; length MUST equal keyLength()
     * @return BackupStream The encrypted stream
     */
    public function spawn(BackupStream $inner, string $key): BackupStream;

    /**
     * Identifier stored on the backups row.
     * Examples: "openssl-aes-256-gcm", "sodium", "none".
     */
    public function name(): string;

    /**
     * Expected raw binary key length in bytes (e.g. 32 for AES-256).
     * Return 0 for drivers that require no key (NullEncryptionDriver).
     */
    public function keyLength(): int;

    /**
     * Wrap $inner (a ciphertext BackupStream) in a decrypting decorator.
     *
     * This is the inverse of spawn(): the returned stream reads encrypted
     * chunks from $inner, decrypts them, and yields plaintext.
     *
     * Implementations MUST:
     *  - Return $inner unchanged when no decryption is needed (NullEncryptionDriver).
     *  - Validate the wire format header (version byte, nonce/header) on first read.
     *  - Decrypt chunk-by-chunk with O(chunk_size) memory — no full-stream buffering.
     *  - Wipe key material from memory on close().
     *
     * @param  BackupStream  $inner  Upstream ciphertext stream (e.g. S3 download body)
     * @param  string  $key  Raw binary key; length MUST equal keyLength()
     * @return BackupStream The decrypted plaintext stream
     */
    public function spawnDecrypt(BackupStream $inner, string $key): BackupStream;
}
