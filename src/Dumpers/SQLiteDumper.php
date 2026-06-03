<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * SQLite dump driver using the sqlite3 CLI `.dump` command.
 *
 * SQLite databases are local files — no network connection, no
 * credentials. The database path is read directly from the Laravel
 * connection config, bypassing DatabaseCredentials entirely.
 */
final class SQLiteDumper extends AbstractProcessDumper
{
    public function __construct(
        BinaryLocator $locator,
        Config $config,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $binary = $this->locator->locate(
            $this->config->get('stream-backup.dump.drivers.sqlite.binary', 'sqlite3')
        );

        $dbPath = $this->resolveDatabasePath($context);

        return array_merge(
            [$binary, $dbPath, '.dump'],
            $context->extraDumpFlags,
        );
    }

    public function name(): string
    {
        return 'sqlite3';
    }

    /**
     * Resolve the absolute path to the SQLite database file.
     *
     * Unlike MySQL/PostgreSQL, SQLite uses a filesystem path rather than
     * host/port/credentials. The path is read from the Laravel connection
     * config and validated for existence.
     *
     * @throws PipelineException if the database file does not exist
     */
    private function resolveDatabasePath(BackupContext $context): string
    {
        $path = (string) $this->config->get(
            "database.connections.{$context->connectionName}.database",
            '',
        );

        if ($path === '') {
            throw new PipelineException(sprintf(
                "No database path configured for SQLite connection '%s'.",
                $context->connectionName,
            ));
        }

        if (! is_file($path)) {
            throw new PipelineException(sprintf(
                "SQLite database file not found for connection '%s': '%s'",
                $context->connectionName,
                $path,
            ));
        }

        return $path;
    }
}
