<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

/**
 * Immutable value object exposing the raw process and pipe resources
 * needed by StreamPipeline for stream_select().
 *
 * Replaces the Reflection-based extractPipes() hack with a clean
 * public API, restoring Liskov compliance for BackupStream.
 */
final class PipeSet
{
    /**
     * @param  resource  $process  proc_open() handle
     * @param  resource  $stdout  pipe 1 (non-blocking)
     * @param  resource|null  $stderr  pipe 2 (non-blocking), may be null if not captured
     */
    public function __construct(
        public readonly mixed $process,
        public readonly mixed $stdout,
        public readonly mixed $stderr,
    ) {}
}
