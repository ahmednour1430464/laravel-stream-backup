<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Template Method base class for process-based database dumpers.
 *
 * Extracts the shared proc_open() + pipe setup boilerplate. Concrete
 * subclasses only need to implement:
 *
 *   - buildCommand()      — the CLI command + args
 *   - buildEnvironment()  — env vars for the child process (optional)
 *   - name()              — human-readable label for logs/errors
 *
 * This design keeps the Open/Closed Principle: adding a new database
 * driver never requires editing this class or any other existing class.
 */
abstract class AbstractProcessDumper implements DatabaseDumper
{
    public function __construct(
        protected readonly BinaryLocator $locator,
        protected readonly Config $config,
    ) {
    }

    /**
     * Start a database dump process and return a non-blocking stream.
     *
     * The Template Method: orchestrates the invariant proc_open flow
     * while delegating command/env construction to subclasses.
     */
    final public function dump(BackupContext $context): BackupStream
    {
        $this->beforeDump($context);

        $command     = $this->buildCommand($context);
        $environment = $this->buildEnvironment($context);

        $descriptors = [
            0 => ['pipe', 'r'],   // stdin  (unused, closed immediately)
            1 => ['pipe', 'w'],   // stdout (dump output)
            2 => ['pipe', 'w'],   // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $environment);

        if (! is_resource($process)) {
            throw new PipelineException(
                sprintf('Failed to start %s process.', $this->name())
            );
        }

        // stdin is unused by dump processes — close it immediately.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new ProcessBackupStream($process, $pipes[1], $pipes[2], $this->name());
    }

    /**
     * Build the CLI command and its arguments.
     *
     * @return array<int, string> e.g. ['/usr/bin/mysqldump', '--quick', 'mydb']
     */
    abstract protected function buildCommand(BackupContext $context): array;

    /**
     * Environment variables merged into the child process.
     *
     * Return null to inherit the parent environment unchanged.
     * Override this to inject credentials via environment variables
     * (e.g. PGPASSWORD for PostgreSQL) instead of the command line.
     *
     * @return array<string, string>|null
     */
    protected function buildEnvironment(BackupContext $context): ?array
    {
        return null;
    }

    /**
     * Hook called before the dump process is spawned.
     *
     * Override to perform setup work like writing credential files.
     * The default implementation is a no-op.
     */
    protected function beforeDump(BackupContext $context): void
    {
        // no-op — subclasses may override
    }

    /**
     * Human-readable label used in error messages and log entries.
     */
    abstract public function name(): string;
}
