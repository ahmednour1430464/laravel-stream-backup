<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\DTOs;

final class DatabaseCredentials
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {
    }
}
