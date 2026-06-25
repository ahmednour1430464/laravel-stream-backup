<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Dumpers;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Dumpers\MySQLDumper;
use Ahmednour\StreamBackup\Dumpers\PostgreSQLDumper;
use Ahmednour\StreamBackup\Dumpers\SQLiteDumper;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Tests\TestCase;

class DumperFactoryTest extends TestCase
{
    private function makeFactory(): DumperFactory
    {
        \assert($this->app !== null);

        return $this->app->make(DumperFactory::class);
    }

    public function test_resolves_mysql_driver(): void
    {
        config()->set('stream-backup.dump.driver', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'root',
            'password' => '',
        ]);

        $factory = $this->makeFactory();
        $dumper = $factory->make('mysql');

        $this->assertInstanceOf(MySQLDumper::class, $dumper);
        $this->assertSame('mysqldump', $dumper->name());
    }

    public function test_resolves_pgsql_driver(): void
    {
        config()->set('stream-backup.dump.driver', 'pgsql');

        $factory = $this->makeFactory();
        $dumper = $factory->make('pgsql');

        $this->assertInstanceOf(PostgreSQLDumper::class, $dumper);
        $this->assertSame('pg_dump', $dumper->name());
    }

    public function test_resolves_sqlite_driver(): void
    {
        config()->set('stream-backup.dump.driver', 'sqlite');

        $factory = $this->makeFactory();
        $dumper = $factory->make('sqlite');

        $this->assertInstanceOf(SQLiteDumper::class, $dumper);
        $this->assertSame('sqlite3', $dumper->name());
    }

    public function test_auto_detects_from_default_connection(): void
    {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.driver', 'mysql');
        config()->set('database.connections.mysql.host', '127.0.0.1');
        config()->set('database.connections.mysql.port', 3306);
        config()->set('database.connections.mysql.database', 'test_db');
        config()->set('database.connections.mysql.username', 'root');
        config()->set('database.connections.mysql.password', '');
        config()->set('stream-backup.dump.driver', 'auto');

        $factory = $this->makeFactory();
        $dumper = $factory->make();

        $this->assertInstanceOf(MySQLDumper::class, $dumper);
    }

    public function test_auto_detects_pgsql_from_default_connection(): void
    {
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');
        config()->set('stream-backup.dump.driver', 'auto');

        $factory = $this->makeFactory();
        $dumper = $factory->make();

        $this->assertInstanceOf(PostgreSQLDumper::class, $dumper);
    }

    public function test_extend_registers_custom_driver(): void
    {
        $factory = $this->makeFactory();

        $factory->extend('custom', function ($app) {
            return new class implements DatabaseDumper
            {
                public function dump(BackupContext $context): BackupStream
                {
                    throw new \RuntimeException('Not implemented.');
                }

                public function name(): string
                {
                    return 'custom-dumper';
                }
            };
        });

        $dumper = $factory->make('custom');
        $this->assertSame('custom-dumper', $dumper->name());
    }

    public function test_unknown_driver_throws_invalid_config_exception(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("Unknown dump driver 'nonexistent'");

        $factory = $this->makeFactory();
        $factory->make('nonexistent');
    }

    public function test_null_driver_uses_global_config(): void
    {
        config()->set('stream-backup.dump.driver', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'root',
            'password' => '',
        ]);

        $factory = $this->makeFactory();
        $dumper = $factory->make(null);

        $this->assertInstanceOf(MySQLDumper::class, $dumper);
    }

    public function test_custom_driver_takes_priority_over_builtin(): void
    {
        $factory = $this->makeFactory();

        // Register a custom 'mysql' driver that overrides the built-in
        $factory->extend('mysql', function ($app) {
            return new class implements DatabaseDumper
            {
                public function dump(BackupContext $context): BackupStream
                {
                    throw new \RuntimeException('Not implemented.');
                }

                public function name(): string
                {
                    return 'custom-mysql';
                }
            };
        });

        $dumper = $factory->make('mysql');
        $this->assertSame('custom-mysql', $dumper->name());
    }
}
