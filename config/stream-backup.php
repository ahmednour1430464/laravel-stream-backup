<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default disk
    |--------------------------------------------------------------------------
    |
    | The Laravel filesystem disk that backups are uploaded to. Must point at
    | an S3-compatible driver (spaces, s3, minio, ...).
    |
    */
    'default_disk' => env('STREAM_BACKUP_DISK', 'spaces'),

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Level 4 is a deliberate default: roughly 80% of the compression ratio
    | of level 6 at ~50% of the CPU cost, which keeps pigz from becoming
    | the bottleneck on databases above ~10 GB.
    |
    */
    'compression' => [
        'driver' => env('STREAM_BACKUP_COMPRESSION_DRIVER', 'pigz'),
        'level'  => (int) env('STREAM_BACKUP_COMPRESSION_LEVEL', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database dump
    |--------------------------------------------------------------------------
    |
    | driver: 'auto' detects from database.default connection driver.
    |         Explicit: 'mysql', 'pgsql', 'sqlite', or any custom driver
    |         registered via DumperFactory::extend().
    |
    | extra_flags: additional CLI flags passed to ALL dump drivers.
    |
    | drivers: per-driver configuration. Each entry specifies the binary
    |          path/name for that driver's CLI tool.
    |
    */
    'dump' => [
        'driver'      => env('STREAM_BACKUP_DUMP_DRIVER', 'auto'),
        'extra_flags' => [],

        'drivers' => [
            'mysql' => [
                // Backward compat: checks STREAM_BACKUP_MYSQLDUMP first
                'binary' => env('STREAM_BACKUP_MYSQLDUMP_BINARY',
                                env('STREAM_BACKUP_MYSQLDUMP', 'mysqldump')),
            ],
            'pgsql' => [
                'binary' => env('STREAM_BACKUP_PGDUMP_BINARY', 'pg_dump'),
            ],
            'sqlite' => [
                'binary' => env('STREAM_BACKUP_SQLITE3_BINARY', 'sqlite3'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming tuning
    |--------------------------------------------------------------------------
    |
    | part_size: size of each S3 multipart upload part. 32 MB keeps the part
    | count well under the 10,000 limit even for 300 GB databases while
    | minimising HTTP round-trips.
    |
    | read_chunk: bytes pulled from each pipe per stream_select iteration.
    |
    */
    'multipart' => [
        'part_size' => 32 * 1024 * 1024,
    ],
    'read_chunk' => 64 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many backups to keep per tier. See RetentionClassifier for the
    | classification rules.
    |
    */
    'retention' => [
        'daily'   => 7,
        'weekly'  => 4,
        'monthly' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | max_concurrent caps the number of backup jobs that may run at the same
    | time across all workers; additional jobs re-queue with delay.
    | slot_ttl is the semaphore lease duration (seconds).
    |
    */
    'queue' => [
        'connection'     => env('STREAM_BACKUP_QUEUE_CONNECTION', 'redis'),
        'queue'          => env('STREAM_BACKUP_QUEUE', 'backups'),
        'max_concurrent' => (int) env('STREAM_BACKUP_MAX_CONCURRENT', 2),
        'slot_ttl'       => 21600, // 6 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenants
    |--------------------------------------------------------------------------
    |
    | Consumed by the default ConfigTenantResolver. Each entry describes one
    | database to back up.
    |
    | Example:
    |   ['connection' => 'tenant_1', 'database' => 'company_1', 'tenant_id' => 1],
    |   ['connection' => 'pg_tenant', 'database' => 'orders', 'tenant_id' => 2, 'driver' => 'pgsql'],
    |
    | Leave this empty to have backup:all fall back to a single BackupContext
    | derived from config('database.default').
    |
    */
    'tenants' => [],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When `auto_schedule` is true, the package registers the cleanup jobs
    | on Laravel's scheduler automatically (see StreamBackupServiceProvider).
    |
    | The `schedule` block below lets you control WHEN those jobs fire
    | without having to disable auto_schedule and wire them yourself.
    |
    | Invalid values throw InvalidConfigException at boot time so typos
    | surface immediately instead of silently running at the wrong cadence.
    |
    |   cleanup.frequency:  'daily' | 'hourly' | 'weekly' | 'monthly' | 'cron'
    |       - daily/weekly/monthly: uses `cleanup.time` (HH:MM, 24h)
    |       - cron:                 uses `cleanup.cron` (raw expression)
    |
    |   stale_multipart.frequency:  'hourly' | 'everyMinutes' | 'cron'
    |       - everyMinutes: uses `stale_multipart.minutes`
    |                       (must divide 60 evenly: 1,2,3,4,5,6,10,12,15,20,30,60)
    |       - cron:         uses `stale_multipart.cron` (raw expression)
    |
    |   stale_multipart.stale_hours:
    |       Hours a multipart upload may remain in 'Uploading' before it is
    |       considered stale and aborted. Floored at 1.
    |
    |   queue / connection (nullable):
    |       If set, the scheduled cleanup jobs are pushed onto this
    |       queue/connection. Leave null to use the app's default queue on
    |       the default connection. NOTE: this is independent of the
    |       `queue` block above, which only routes RunBackupJob.
    |
    */
    'auto_schedule'       => env('STREAM_BACKUP_AUTO_SCHEDULE', true),
    'verify_after_upload' => true,

    'schedule' => [
        'timezone'   => env('STREAM_BACKUP_SCHEDULE_TZ'),
        'connection' => env('STREAM_BACKUP_CLEANUP_CONNECTION'),
        'queue'      => env('STREAM_BACKUP_CLEANUP_QUEUE'),

        'cleanup' => [
            'frequency' => env('STREAM_BACKUP_CLEANUP_FREQUENCY', 'daily'),
            'time'      => env('STREAM_BACKUP_CLEANUP_TIME', '03:15'),
            'cron'      => env('STREAM_BACKUP_CLEANUP_CRON'),
        ],

        'stale_multipart' => [
            'frequency'   => env('STREAM_BACKUP_STALE_FREQUENCY', 'hourly'),
            'minutes'     => (int) env('STREAM_BACKUP_STALE_MINUTES', 60),
            'cron'        => env('STREAM_BACKUP_STALE_CRON'),
            'stale_hours' => max(1, (int) env('STREAM_BACKUP_STALE_HOURS', 6)),
        ],
    ],

];
