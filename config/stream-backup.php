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
    | mysqldump
    |--------------------------------------------------------------------------
    */
    'dump' => [
        'binary'      => env('STREAM_BACKUP_MYSQLDUMP', 'mysqldump'),
        'extra_flags' => [],
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
    */
    'auto_schedule'       => true,
    'verify_after_upload' => true,

];
