<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\PostgreSQLDumper;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Tests\TestCase;
use ReflectionMethod;

class PostgreSQLDumperTest extends TestCase
{
    private function makeDumper(): PostgreSQLDumper
    {
        \assert($this->app !== null);

        return $this->app->make(PostgreSQLDumper::class);
    }

    private function makeContext(): BackupContext
    {
        return new BackupContext(
            tenantId: null,
            databaseName: 'test_db',
            connectionName: 'pgsql',
            disk: 'spaces',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'test_db',
            'username' => 'pguser',
            'password' => 's3cret_pa$$word',
        ]);

        // Point the binary to a known executable so BinaryLocator resolves
        // without requiring pg_dump to be installed on the test runner.
        config()->set('stream-backup.dump.drivers.pgsql.binary', '/bin/true');
    }

    public function test_name_returns_pg_dump(): void
    {
        $dumper = $this->makeDumper();
        $this->assertSame('pg_dump', $dumper->name());
    }

    public function test_build_command_contains_correct_args(): void
    {
        $dumper = $this->makeDumper();
        $context = $this->makeContext();

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $command = $method->invoke($dumper, $context);

        // Should contain host, port, user, database, --no-password, -F p
        $this->assertContains('-h', $command);
        $this->assertContains('127.0.0.1', $command);
        $this->assertContains('-p', $command);
        $this->assertContains('5432', $command);
        $this->assertContains('-U', $command);
        $this->assertContains('pguser', $command);
        $this->assertContains('-d', $command);
        $this->assertContains('test_db', $command);
        $this->assertContains('--no-password', $command);
        $this->assertContains('-F', $command);
        $this->assertContains('p', $command);
    }

    public function test_password_not_in_command_array(): void
    {
        $dumper = $this->makeDumper();
        $context = $this->makeContext();

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $command = $method->invoke($dumper, $context);

        // The password must NEVER appear in the command array (security)
        $this->assertNotContains('s3cret_pa$$word', $command);
    }

    public function test_build_environment_contains_pgpassword(): void
    {
        $dumper = $this->makeDumper();
        $context = $this->makeContext();

        $method = new ReflectionMethod($dumper, 'buildEnvironment');
        $method->setAccessible(true);
        $env = $method->invoke($dumper, $context);

        $this->assertIsArray($env);
        $this->assertArrayHasKey('PGPASSWORD', $env);
        $this->assertSame('s3cret_pa$$word', $env['PGPASSWORD']);
    }

    public function test_extra_dump_flags_are_appended(): void
    {
        $context = new BackupContext(
            tenantId: null,
            databaseName: 'test_db',
            connectionName: 'pgsql',
            disk: 'spaces',
            extraDumpFlags: ['--verbose', '--no-owner'],
        );

        $dumper = $this->makeDumper();

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $command = $method->invoke($dumper, $context);

        $this->assertContains('--verbose', $command);
        $this->assertContains('--no-owner', $command);
    }

    public function test_unconfigured_connection_throws(): void
    {
        config()->set('database.connections.nonexistent', null);

        $context = new BackupContext(
            tenantId: null,
            databaseName: 'test_db',
            connectionName: 'nonexistent',
            disk: 'spaces',
        );

        $dumper = $this->makeDumper();

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage("'nonexistent' is not configured");

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $method->invoke($dumper, $context);
    }
}
