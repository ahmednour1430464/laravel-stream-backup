<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\SQLiteDumper;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Tests\TestCase;
use ReflectionMethod;

class SQLiteDumperTest extends TestCase
{
    private function makeDumper(): SQLiteDumper
    {
        return $this->app->make(SQLiteDumper::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Point to a known executable so BinaryLocator resolves without
        // requiring sqlite3 to be installed on the test runner.
        config()->set('stream-backup.dump.drivers.sqlite.binary', '/bin/true');
    }

    public function test_name_returns_sqlite3(): void
    {
        $dumper = $this->makeDumper();
        $this->assertSame('sqlite3', $dumper->name());
    }

    public function test_build_command_contains_database_path_and_dot_dump(): void
    {
        // Create a real temp file to satisfy the is_file() check
        $tempDb = tempnam(sys_get_temp_dir(), 'sqlite_test_');
        file_put_contents($tempDb, '');

        config()->set('database.connections.sqlite_test', [
            'driver'   => 'sqlite',
            'database' => $tempDb,
        ]);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'sqlite_test',
            disk:           'spaces',
        );

        $dumper = $this->makeDumper();

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $command = $method->invoke($dumper, $context);

        $this->assertContains($tempDb, $command);
        $this->assertContains('.dump', $command);

        @unlink($tempDb);
    }

    public function test_throws_when_database_file_not_found(): void
    {
        config()->set('database.connections.sqlite_missing', [
            'driver'   => 'sqlite',
            'database' => '/nonexistent/path/to/database.sqlite',
        ]);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'sqlite_missing',
            disk:           'spaces',
        );

        $dumper = $this->makeDumper();

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('SQLite database file not found');

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $method->invoke($dumper, $context);
    }

    public function test_throws_when_no_database_path_configured(): void
    {
        config()->set('database.connections.sqlite_empty', [
            'driver'   => 'sqlite',
            'database' => '',
        ]);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'sqlite_empty',
            disk:           'spaces',
        );

        $dumper = $this->makeDumper();

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('No database path configured');

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $method->invoke($dumper, $context);
    }

    public function test_extra_dump_flags_are_appended(): void
    {
        $tempDb = tempnam(sys_get_temp_dir(), 'sqlite_test_');
        file_put_contents($tempDb, '');

        config()->set('database.connections.sqlite_flags', [
            'driver'   => 'sqlite',
            'database' => $tempDb,
        ]);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'sqlite_flags',
            disk:           'spaces',
            extraDumpFlags: ['-bail'],
        );

        $dumper = $this->makeDumper();

        $method = new ReflectionMethod($dumper, 'buildCommand');
        $method->setAccessible(true);
        $command = $method->invoke($dumper, $context);

        $this->assertContains('-bail', $command);

        @unlink($tempDb);
    }

    public function test_build_environment_returns_null(): void
    {
        $dumper = $this->makeDumper();

        $method = new ReflectionMethod($dumper, 'buildEnvironment');
        $method->setAccessible(true);

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'test_db',
            connectionName: 'sqlite_test',
            disk:           'spaces',
        );

        $result = $method->invoke($dumper, $context);

        // SQLite doesn't need any environment variables
        $this->assertNull($result);
    }
}
