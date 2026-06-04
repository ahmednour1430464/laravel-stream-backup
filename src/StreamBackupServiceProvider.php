<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup;

use Ahmednour\StreamBackup\Commands\BackupAllCommand;
use Ahmednour\StreamBackup\Commands\BackupCleanupCommand;
use Ahmednour\StreamBackup\Commands\BackupTenantCommand;
use Ahmednour\StreamBackup\Compression\GzipDriver;
use Ahmednour\StreamBackup\Compression\PigzDriver;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Encryption\NullEncryptionDriver;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Encryption\SodiumDriver;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Contracts\DatabaseDumper;
use Ahmednour\StreamBackup\Contracts\TenantResolver;
use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
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
use Illuminate\Console\Scheduling\Event;
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
            $config  = $app->make(Config::class);
            $drivers = (array) $config->get('stream-backup.dump.drivers', []);

            return new BinaryLocator([
                'mysqldump' => (string) ($drivers['mysql']['binary']  ?? 'mysqldump'),
                'pg_dump'   => (string) ($drivers['pgsql']['binary']  ?? 'pg_dump'),
                'sqlite3'   => (string) ($drivers['sqlite']['binary'] ?? 'sqlite3'),
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

        // DumperFactory — singleton so extend() registrations persist.
        // Third-party packages call DumperFactory::extend() in their
        // ServiceProvider::boot() to register custom drivers (OCP).
        $this->app->singleton(DumperFactory::class);

        // EncryptionFactory — singleton so extend() registrations persist.
        // Third-party packages call EncryptionFactory::extend() in their
        // ServiceProvider::boot() to register custom encryption drivers (OCP).
        $this->app->singleton(EncryptionFactory::class);

        // EncryptionKeyResolver — resolves raw binary key from env/file.
        // Singleton is safe: it holds no key state (key is returned, not cached).
        $this->app->singleton(EncryptionKeyResolver::class);

        // DatabaseDumper resolves through the factory (global default).
        // StreamPipeline uses DumperFactory directly for per-context
        // resolution, but this binding exists for any code that injects
        // the DatabaseDumper contract directly.
        $this->app->bind(DatabaseDumper::class, function ($app) {
            return $app->make(DumperFactory::class)->make();
        });

        // Uploader
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
            $config = $this->app->make(Config::class);

            if (! $config->get('stream-backup.auto_schedule', true)) {
                return;
            }

            $staleHours = max(1, (int) $config->get('stream-backup.schedule.stale_multipart.stale_hours', 6));
            $connection = $config->get('stream-backup.schedule.connection');
            $queue      = $config->get('stream-backup.schedule.queue');
            $timezone   = $config->get('stream-backup.schedule.timezone');

            $staleEvent = $schedule->job(
                new AbortStaleMultipartUploads($staleHours),
                $queue,
                $connection,
            );
            $this->applyFrequency($staleEvent, (array) $config->get('stream-backup.schedule.stale_multipart', []), 'stale_multipart');

            $cleanupEvent = $schedule->job(new BackupCleanupJob(), $queue, $connection);
            $this->applyFrequency($cleanupEvent, (array) $config->get('stream-backup.schedule.cleanup', []), 'cleanup');

            if (is_string($timezone) && $timezone !== '') {
                $staleEvent->timezone($timezone);
                $cleanupEvent->timezone($timezone);
            }
        });
    }

    /**
     * Valid frequency values per job kind. Anything else throws so that
     * typos surface immediately instead of silently falling back to a
     * default cadence (which for AbortStaleMultipartUploads would mean
     * leaking S3 multipart-upload cost for up to 24h).
     */
    private const VALID_FREQUENCIES = [
        'cleanup'         => ['daily', 'hourly', 'weekly', 'monthly', 'cron'],
        'stale_multipart' => ['hourly', 'everyMinutes', 'cron'],
    ];

    /**
     * Cron `*\/N` expressions only fire evenly when N divides 60.
     */
    private const VALID_EVERY_MINUTES = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];

    /**
     * Apply the configured frequency to a scheduled job event.
     */
    private function applyFrequency(Event $event, array $cfg, string $kind): void
    {
        $default   = $kind === 'cleanup' ? 'daily' : 'hourly';
        $frequency = (string) ($cfg['frequency'] ?? $default);
        $allowed   = self::VALID_FREQUENCIES[$kind];

        if (! in_array($frequency, $allowed, true)) {
            throw new InvalidConfigException(sprintf(
                "stream-backup.schedule.%s.frequency must be one of [%s]; got '%s'.",
                $kind,
                implode(', ', $allowed),
                $frequency,
            ));
        }

        match ($frequency) {
            'cron'         => $event->cron($this->requireCron($cfg, $kind)),
            'hourly'       => $event->hourly(),
            'daily'        => $event->dailyAt($this->requireTime($cfg, $kind)),
            'weekly'       => $event->weeklyOn(0, $this->requireTime($cfg, $kind)),
            'monthly'      => $event->monthlyOn(1, $this->requireTime($cfg, $kind)),
            'everyMinutes' => $event->cron($this->buildEveryMinutesExpr($cfg, $kind)),
        };
    }

    private function requireCron(array $cfg, string $kind): string
    {
        $cron = (string) ($cfg['cron'] ?? '');
        if ($cron === '') {
            throw new InvalidConfigException(
                "stream-backup.schedule.{$kind}.cron must be set when frequency is 'cron'."
            );
        }
        return $cron;
    }

    private function requireTime(array $cfg, string $kind): string
    {
        $time = (string) ($cfg['time'] ?? '');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new InvalidConfigException(
                "stream-backup.schedule.{$kind}.time must be 'HH:MM' (24h); got '{$time}'."
            );
        }
        return $time;
    }

    private function buildEveryMinutesExpr(array $cfg, string $kind): string
    {
        $minutes = (int) ($cfg['minutes'] ?? 0);
        if (! in_array($minutes, self::VALID_EVERY_MINUTES, true)) {
            throw new InvalidConfigException(
                "stream-backup.schedule.{$kind}.minutes must divide 60 evenly; "
                . 'use one of [' . implode(', ', self::VALID_EVERY_MINUTES) . '].'
            );
        }
        return "*/{$minutes} * * * *";
    }
}
