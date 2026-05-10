<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\MySQLCredentialFile;
use Illuminate\Contracts\Config\Repository as Config;

final class MySQLDumper implements DatabaseDumper
{
    public function __construct(
        private readonly BinaryLocator $locator,
        private readonly MySQLCredentialFile $credentialFile,
        private readonly Config $config,
    ) {
    }

    public function dump(BackupContext $context): BackupStream
    {
        $credentials = $this->resolveCredentials($context);
        $cnfPath     = $this->credentialFile->write($credentials);
        $binary      = $this->locator->locate($this->config->get('stream-backup.dump.binary', 'mysqldump'));

        $args = array_merge(
            [
                $binary,
                '--defaults-extra-file=' . $cnfPath,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
            ],
            (array) $this->config->get('stream-backup.dump.extra_flags', []),
            $context->extraDumpFlags,
            [$credentials->database],
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($args, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new PipelineException('Failed to start mysqldump process.');
        }

        // stdin unused
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new ProcessBackupStream($process, $pipes[1], $pipes[2], 'mysqldump');
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
            host:     (string) ($conn['host']     ?? '127.0.0.1'),
            port:     (int)    ($conn['port']     ?? 3306),
            database: $context->databaseName,
            username: (string) ($conn['username'] ?? ''),
            password: (string) ($conn['password'] ?? ''),
        );
    }
}
