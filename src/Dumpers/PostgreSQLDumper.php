<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * PostgreSQL dump driver using pg_dump.
 *
 * Credentials are passed via the PGPASSWORD environment variable —
 * the password never appears on the command line or in `ps aux`.
 * This follows PostgreSQL's own recommended practice for non-interactive
 * authentication in scripted environments.
 */
final class PostgreSQLDumper extends AbstractProcessDumper
{
    public function __construct(
        BinaryLocator $locator,
        Config $config,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $credentials = $this->resolveCredentials($context);
        $binary = $this->locator->locate(
            $this->config->get('stream-backup.dump.drivers.pgsql.binary', 'pg_dump')
        );

        return array_merge(
            [
                $binary,
                '-h', $credentials->host,
                '-p', (string) $credentials->port,
                '-U', $credentials->username,
                '-d', $credentials->database,
                '--no-password',  // fail rather than prompt
                '-F', 'p',       // plain SQL format (pipeable)
            ],
            (array) $this->config->get('stream-backup.dump.extra_flags', []),
            $context->extraDumpFlags,
        );
    }

    /**
     * Pass credentials via PGPASSWORD environment variable.
     *
     * Inherits the full parent environment and adds PGPASSWORD so that
     * pg_dump authenticates without the password appearing in the process
     * list or command line.
     *
     * @return array<string, string>
     */
    protected function buildEnvironment(BackupContext $context): array
    {
        $credentials = $this->resolveCredentials($context);

        // Inherit parent environment and inject PGPASSWORD
        $env = getenv();
        if (! is_array($env)) {
            $env = [];
        }
        $env['PGPASSWORD'] = $credentials->password;

        return $env;
    }

    public function name(): string
    {
        return 'pg_dump';
    }

    private function resolveCredentials(BackupContext $context): DatabaseCredentials
    {
        $key = "database.connections.{$context->connectionName}";
        $conn = (array) $this->config->get($key, []);

        if ($conn === []) {
            throw new PipelineException(sprintf(
                "Database connection '%s' is not configured.",
                $context->connectionName,
            ));
        }

        return new DatabaseCredentials(
            host: (string) ($conn['host'] ?? '127.0.0.1'),
            port: (int) ($conn['port'] ?? 5432),
            database: $context->databaseName,
            username: (string) ($conn['username'] ?? ''),
            password: (string) ($conn['password'] ?? ''),
        );
    }
}
