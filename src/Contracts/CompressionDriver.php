<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

interface CompressionDriver
{
    /**
     * The shell command + args for the compression process, e.g.
     * ['pigz', '-4', '-c'].
     *
     * @return array<int, string>
     */
    public function buildCommand(): array;

    /**
     * Identifier persisted on the backups row (e.g. "pigz", "gzip").
     */
    public function name(): string;
}
