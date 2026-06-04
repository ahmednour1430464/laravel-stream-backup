<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Encryption;

use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container as Application;

/**
 * Abstract Factory for encryption drivers.
 *
 * Built-in drivers (none, openssl-aes-256-gcm, sodium) are resolved from
 * the container. Third-party packages or the host application can register
 * custom drivers via extend() — no source-file edits required (OCP).
 *
 * This class intentionally mirrors DumperFactory's design so developers
 * already familiar with extending dumpers can apply the same pattern.
 *
 * Usage from a ServiceProvider::boot():
 *
 *     $this->app->make(EncryptionFactory::class)->extend('age', function ($app) {
 *         return new AgeDriver($app->make(BinaryLocator::class));
 *     });
 */
final class EncryptionFactory
{
    /** @var array<string, Closure(Application): EncryptionDriver> */
    private array $customDrivers = [];

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {
    }

    /**
     * Register a custom encryption driver.
     *
     * @param string  $name    Driver name used in config (e.g. 'age', 'gpg')
     * @param Closure $factory fn(Application): EncryptionDriver
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customDrivers[$name] = $factory;
    }

    /**
     * Resolve the encryption driver.
     *
     * Resolution order:
     *   1. Custom drivers registered via extend()
     *   2. Built-in drivers (none, openssl-aes-256-gcm, sodium)
     *   3. InvalidConfigException
     *
     * @param string|null $driver  null = use global config value
     */
    public function make(?string $driver = null): EncryptionDriver
    {
        $driver ??= (string) $this->config->get('stream-backup.encryption.driver', 'none');

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($this->app);
        }

        return match ($driver) {
            'none'                => $this->app->make(NullEncryptionDriver::class),
            'openssl-aes-256-gcm' => $this->app->make(OpenSslAes256GcmDriver::class),
            'sodium'              => $this->app->make(SodiumDriver::class),
            default               => throw new InvalidConfigException(
                "Unknown encryption driver '{$driver}'. " .
                'Register it via EncryptionFactory::extend() or use one of: none, openssl-aes-256-gcm, sodium.'
            ),
        };
    }
}
