<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup;

use Ahmednour\StreamBackup\Commands\BackupAllCommand;
use Ahmednour\StreamBackup\Commands\BackupCleanupCommand;
use Ahmednour\StreamBackup\Commands\BackupTenantCommand;
use Ahmednour\StreamBackup\Compression\GzipDriver;
use Ahmednour\StreamBackup\Compression\PigzDriver;
use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\Contracts\TenantResolver;
use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\Dumpers\MySQLDumper;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Jobs\AbortStaleMultipartUploads;
use Ahmednour\StreamBackup\Jobs\BackupCleanupJob;
use Ahmednour\StreamBackup\Resolvers\ConfigTenantResolver;
use Ahmednour\StreamBackup\Resolvers\SingleDatabaseResolver;
use Ahmednour\StreamBackup\Support\BackupPathBuilder;
use Ahmednour\StreamBackup\Support\BackupSemaphore;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\MySQLCredentialFile;
use Ahmednour\StreamBackup\Support\RetentionClassifier;
use Ahmednour\StreamBackup\Uploaders\S3MultipartUploader;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;

class StreamBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/stream-backup.php', 'stream-backup');

        // Core singletons
        $this->app->singleton(MySQLCredentialFile::class);

        $this->app->singleton(BinaryLocator::class, function ($app) {
            $config = $app->make(Config::class);
            return new BinaryLocator([
                'mysqldump' => (string) $config->get('stream-backup.dump.binary', 'mysqldump'),
            ]);
        });

        $this->app->singleton(BackupPathBuilder::class);
        $this->app->singleton(RetentionClassifier::class);

        $this->app->singleton(BackupSemaphore::class, function ($app) {
            $config = $app->make(Config::class);
            return new BackupSemaphore(
                cache:          $app->make(CacheRepository::class),
                maxConcurrent:  (int) $config->get('stream-backup.queue.max_concurrent', 2),
                lockTtl:        (int) $config->get('stream-backup.queue.slot_ttl', 21600),
            );
        });

        // S3 client. The checksum options are mandatory for DigitalOcean
        // Spaces compatibility (see S3MultipartUploader docblock).
        $this->app->singleton(S3ClientInterface::class, function ($app) {
            $config    = $app->make(Config::class);
            $diskName  = (string) $config->get('stream-backup.default_disk', 'spaces');
            $diskCfg   = (array) $config->get("filesystems.disks.{$diskName}", []);

            return new S3Client([
                'version'                      => 'latest',
                'region'                       => $diskCfg['region']   ?? 'us-east-1',
                'endpoint'                     => $diskCfg['endpoint'] ?? null,
                'credentials'                  => [
                    'key'    => $diskCfg['key']    ?? '',
                    'secret' => $diskCfg['secret'] ?? '',
                ],
                'use_path_style_endpoint'      => (bool) ($diskCfg['use_path_style_endpoint'] ?? false),
                'request_checksum_calculation' => 'when_required',
                'response_checksum_validation' => 'when_required',
            ]);
        });

        $this->app->singleton(BackupVerifier::class);

        // Compression driver switch
        $this->app->bind(CompressionDriver::class, function ($app) {
            $config = $app->make(Config::class);
            $driver = (string) $config->get('stream-backup.compression.driver', 'pigz');
            $level  = (int) $config->get('stream-backup.compression.level', 4);
            $locator = $app->make(BinaryLocator::class);

            return match ($driver) {
                'pigz' => new PigzDriver($locator, $level),
                'gzip' => new GzipDriver($locator, $level),
                default => throw new InvalidConfigException("Unknown compression driver '{$driver}'."),
            };
        });

        // Dumper and uploader
        $this->app->bind(DatabaseDumper::class, MySQLDumper::class);
        $this->app->bind(UploadDriver::class, S3MultipartUploader::class);

        // Tenant resolver: if the tenants array is empty, fall back to a
        // single-database resolver so `backup:all` works on non-multi-tenant apps.
        $this->app->bind(TenantResolver::class, function ($app) {
            $config = $app->make(Config::class);
            $tenants = (array) $config->get('stream-backup.tenants', []);

            return $tenants === []
                ? $app->make(SingleDatabaseResolver::class)
                : $app->make(ConfigTenantResolver::class);
        });

        $this->app->singleton(BackupManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/stream-backup.php' => config_path('stream-backup.php'),
            ], 'stream-backup-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'stream-backup-migrations');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                BackupTenantCommand::class,
                BackupAllCommand::class,
                BackupCleanupCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! $this->app->make(Config::class)->get('stream-backup.auto_schedule', true)) {
                return;
            }
            $schedule->job(new AbortStaleMultipartUploads())->hourly();
            $schedule->job(new BackupCleanupJob())->dailyAt('03:15');
        });
    }
}
