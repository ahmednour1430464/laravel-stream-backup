<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Abstract Factory for database dumper drivers.
 *
 * Built-in drivers (mysql, pgsql, sqlite) are resolved from the
 * container. Third-party packages or the host application can register
 * custom drivers via extend() — no source-file edits required (OCP).
 *
 * Usage from a ServiceProvider::boot():
 *
 *     $this->app->make(DumperFactory::class)->extend('mongodb', function ($app) {
 *         return new MongoDBDumper($app->make(BinaryLocator::class), ...);
 *     });
 */
final class DumperFactory
{
    /** @var array<string, Closure(Application): DatabaseDumper> */
    private array $customDrivers = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    /**
     * Register a custom dumper driver.
     *
     * @param  string  $name  Driver name (e.g. 'mongodb', 'mariadb')
     * @param  Closure  $factory  fn(Application): DatabaseDumper
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customDrivers[$name] = $factory;
    }

    /**
     * Resolve the dumper for a given driver name.
     *
     * Resolution order:
     *   1. Custom drivers registered via extend()
     *   2. Built-in drivers (mysql, pgsql, sqlite)
     *   3. InvalidConfigException
     *
     * @param  string|null  $driver  null = use global config, 'auto' = detect from default connection
     */
    public function make(?string $driver = null): DatabaseDumper
    {
        $driver ??= (string) $this->config->get('stream-backup.dump.driver', 'auto');

        if ($driver === 'auto') {
            $driver = $this->detectFromConnection();
        }

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($this->app);
        }

        return match ($driver) {
            'mysql' => $this->app->make(MySQLDumper::class),
            'pgsql' => $this->app->make(PostgreSQLDumper::class),
            'sqlite' => $this->app->make(SQLiteDumper::class),
            default => throw new InvalidConfigException(
                "Unknown dump driver '{$driver}'. "
                .'Register it via DumperFactory::extend() or use one of: mysql, pgsql, sqlite.'
            ),
        };
    }

    /**
     * Auto-detect the dump driver from Laravel's default database connection.
     *
     * Maps the Laravel connection driver (mysql, pgsql, sqlite, etc.)
     * to the corresponding dump driver name.
     */
    private function detectFromConnection(): string
    {
        $connection = (string) $this->config->get('database.default', 'mysql');

        return (string) $this->config->get(
            "database.connections.{$connection}.driver",
            'mysql',
        );
    }
}
