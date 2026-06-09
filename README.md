# laravel-stream-backup

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ahmednour1430464/laravel-stream-backup.svg?style=flat-square)](https://packagist.org/packages/ahmednour1430464/laravel-stream-backup)
[![Total Downloads](https://img.shields.io/packagist/dt/ahmednour1430464/laravel-stream-backup.svg?style=flat-square)](https://packagist.org/packages/ahmednour1430464/laravel-stream-backup)
[![License](https://img.shields.io/packagist/l/ahmednour1430464/laravel-stream-backup.svg?style=flat-square)](https://github.com/ahmednour1430464/laravel-stream-backup/blob/main/LICENSE.md)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ahmednour1430464/laravel-stream-backup/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ahmednour1430464/laravel-stream-backup/actions?query=workflow%3Atests+branch%3Amain)

> Streaming database → compress → S3 multipart backups **AND** S3 streaming download → decompress → transaction restores for Laravel 10+, with **constant memory use** regardless of database size.

Supports **MySQL**, **PostgreSQL**, **SQLite**, and **custom drivers** via the extensible `DumperFactory`.

**Backup**: The dump process is piped to a compressor (pigz/gzip) which is piped directly into S3 multipart uploads. Nothing is ever buffered to disk and nothing exceeds the 32 MB part buffer in RAM, so a 300 GB database and a 3 GB database use roughly the same amount of memory.

**Restore**: S3 objects are downloaded as a stream, decompressed on the fly, and parsed to restore either full databases or specific tables directly into a database transaction, without buffering the backup file to disk.

This package is the productised form of the proof-of-concept script [`backup.php`](./backup.php).

---

## Comparison with `spatie/laravel-backup`

While `spatie/laravel-backup` is an excellent and widely used package, it has a fundamental limitation when dealing with large databases: **it requires significant local disk space**.

| Feature | `spatie/laravel-backup` | `laravel-stream-backup` |
| --- | --- | --- |
| **Backup Process** | Dumps to local disk → Zips on disk → Uploads to S3 | Streams dump to compressor → Streams directly to S3 |
| **Local Disk Required** | Yes (>100% of DB size) | **No (Zero bytes)** |
| **Memory Usage** | Variable | **Constant (~32 MB buffer)** |
| **Restore Process** | ❌ No built-in restore | Streams from S3 → Decompresses to temp file → Imports |
| **Best For** | Small to medium databases | Large databases & multi-tenant setups |

---

## Requirements

- PHP `^8.1` with the `pcntl` and `hash` extensions (PHP `^8.2` required when running on Laravel 11)
- Laravel `^10.0`, `^11.0`, `^12.0`, or `^13.0`
- An S3-compatible bucket (AWS S3, DigitalOcean Spaces, MinIO, ...)
- One of the following dump tools on `PATH`:
  - **MySQL**: `mysqldump`
  - **PostgreSQL**: `pg_dump`
  - **SQLite**: `sqlite3`
- `pigz` on `PATH` (falls back to built-in `gzip` driver if unavailable)

### Compatibility matrix

| Package | PHP | Laravel | Testbench | PHPUnit |
|---|---|---|---|---|
| `ahmednour1430464/laravel-stream-backup` | `8.1 / 8.2 / 8.3 / 8.4` | `10.*` | `8.*` | `10.*` |
| `ahmednour1430464/laravel-stream-backup` | `8.2 / 8.3 / 8.4` | `11.*` | `9.*` | `10.* / 11.*` |
| `ahmednour1430464/laravel-stream-backup` | `8.2 / 8.3 / 8.4` | `12.*` | `10.*` | `11.* / 12.*` |
| `ahmednour1430464/laravel-stream-backup` | `8.3 / 8.4` | `13.*` | `11.*` | `11.* / 12.*` |

## Supported Databases

| Database   | Dump Tool    | Credential Handling | Notes |
|---|---|---|---|
| MySQL      | `mysqldump`  | Temp credential file (`--defaults-extra-file`) | Default; backward compatible |
| PostgreSQL | `pg_dump`    | `PGPASSWORD` environment variable | Password never on CLI |
| SQLite     | `sqlite3`    | N/A (file-based, no auth) | Reads path from Laravel config |
| Custom     | Your choice  | Your choice | Register via `DumperFactory::extend()` |

## Installation

```bash
composer require ahmednour1430464/laravel-stream-backup
php artisan vendor:publish --tag=stream-backup-config
php artisan vendor:publish --tag=stream-backup-migrations
php artisan migrate
```

The service provider is auto-discovered.

## Environment

Add these to `.env` (see [`.env.example`](./.env.example) for the full list):

```dotenv
STREAM_BACKUP_DISK=spaces
STREAM_BACKUP_COMPRESSION_DRIVER=pigz
STREAM_BACKUP_COMPRESSION_LEVEL=4
STREAM_BACKUP_MAX_CONCURRENT=2
STREAM_BACKUP_QUEUE_CONNECTION=redis
STREAM_BACKUP_QUEUE=backups

# Database dump driver: 'auto' (default), 'mysql', 'pgsql', 'sqlite'
STREAM_BACKUP_DUMP_DRIVER=auto
```

When set to `auto`, the dump driver is detected from your default Laravel database connection (`database.default`).

### DigitalOcean Spaces compatibility

The bundled S3 client is configured with:

```php
'request_checksum_calculation' => 'when_required',
'response_checksum_validation' => 'when_required',
```

These two flags are **mandatory** for Spaces — without them `completeMultipartUpload` fails with `MalformedXML` because Spaces does not implement the SDK's new default checksum headers.

## Usage

### Single database backup

Leave `stream-backup.tenants` empty to fall back to the configured default connection:

```bash
php artisan backup:all
```

### Multiple databases / multi-tenant

Populate `config/stream-backup.php`:

```php
'tenants' => [
    ['connection' => 'tenant_1', 'database' => 'company_1', 'tenant_id' => 1],
    ['connection' => 'tenant_2', 'database' => 'company_2', 'tenant_id' => 2],
    // Mixed database engines: override the driver per-tenant
    ['connection' => 'pg_tenant', 'database' => 'orders', 'tenant_id' => 3, 'driver' => 'pgsql'],
],
```

Then:

```bash
php artisan backup:all                     # enqueue every tenant
php artisan backup:tenant 1                # single tenant by id
php artisan backup:cleanup                 # apply retention policy
```

### Restore

You can restore a backup directly from S3 without downloading the entire file to disk.

```bash
# Full restore
php artisan backup:restore 123

# Restore specific tables only
php artisan backup:restore 123 --tables=users,posts

# Restore into a different connection (e.g. staging)
php artisan backup:restore 123 --connection=staging
```

### Custom Dump Drivers

Register custom drivers in your `AppServiceProvider` or a package service provider:

```php
use Ahmednour\StreamBackup\Dumpers\DumperFactory;

public function boot(): void
{
    $this->app->make(DumperFactory::class)->extend('mongodb', function ($app) {
        return new MongoDBDumper(/* ... */);
    });
}
```

Then reference the driver in config or per-tenant:

```php
// Global
'dump' => ['driver' => 'mongodb'],

// Per-tenant
['connection' => 'mongo', 'database' => 'analytics', 'driver' => 'mongodb'],
```

### Scheduling

`auto_schedule` (enabled by default) registers:

- `stream-backup:abort-stale-multipart` hourly — aborts orphaned multipart uploads on the bucket
- `stream-backup:cleanup` daily at 03:15 UTC — prunes old backups per retention tier

Add your own `backup:all` cadence to your app's scheduler.

## Configuration overview

| Key | Default | Purpose |
|---|---|---|
| `default_disk` | `spaces` | Filesystem disk; must be S3-compatible |
| `dump.driver` | `auto` | `auto`, `mysql`, `pgsql`, `sqlite`, or custom |
| `dump.drivers.mysql.binary` | `mysqldump` | Path/name of the mysqldump binary |
| `dump.drivers.pgsql.binary` | `pg_dump` | Path/name of the pg_dump binary |
| `dump.drivers.sqlite.binary` | `sqlite3` | Path/name of the sqlite3 binary |
| `compression.driver` | `pigz` | `pigz` (parallel) or `gzip` (single-threaded fallback) |
| `compression.level` | `4` | Level 4 trades ~20% ratio for ~50% less CPU vs level 6 |
| `multipart.part_size` | 32 MB | Must be ≥ 5 MB; keeps part count < 10 000 even at 300 GB |
| `read_chunk` | 64 KB | Bytes pulled per `stream_select` iteration |
| `retention.daily` | 7 | Daily backups kept |
| `retention.weekly` | 4 | Weekly (non-last-Sunday) backups kept |
| `retention.monthly` | 6 | Monthly (last Sunday of month) backups kept |
| `queue.max_concurrent` | 2 | Atomic semaphore cap across all workers |
| `queue.slot_ttl` | 21 600 s | Semaphore lease (6 h) |
| `verify_after_upload` | `true` | Validates object size + gzip magic bytes after completion |

## Architecture

### Backup Pipeline

```
dump process (mysqldump / pg_dump / sqlite3)
   │ stdout (non-blocking)
   ▼
  compressor (pigz / gzip)
   │ stdout (non-blocking)
   ▼
 ChecksumStream (SHA-256 tee)
   │
   ▼
 S3 multipart (32 MB parts, php://temp buffer)
```

### Restore Pipeline

```
S3 GetObject (stream)
   │
   ▼
decrypt (if encrypted)
   │
   ▼
decompressor (pigz -d / gzip -d)
   │ stdout (non-blocking)
   ▼
SqlDumpParser (extracts requested tables)
   │
   ▼
TableRestorer (runs inside a DB transaction)
```

### Design Patterns

| Pattern | Where | Purpose |
|---|---|---|
| **Strategy** | `DatabaseDumper` interface + concrete dumpers | Swappable dump algorithms |
| **Template Method** | `AbstractProcessDumper` | Shared proc_open boilerplate |
| **Abstract Factory** | `DumperFactory` with `extend()` | Driver resolution + extensibility |
| **Dependency Inversion** | `StreamPipeline` depends on `DumperFactory`, not concrete dumpers | Decoupled pipeline |
| **Open/Closed** | New drivers = new class + `extend()` call, zero edits to existing code | Extensibility |

### Key design points

- **Write-side `stream_select`** with a `$pendingChunk` buffer — never busy-waits on blocked pipes.
- **Exit-code validation before `completeMultipartUpload`** — refuses to commit objects when the dump or compression process exited non-zero or printed recognisable errors to stderr.
- **`php://temp` part buffer** — zero copy, spills to disk past 2 MB.
- **Secure credential handling** — MySQL: temp file with `chmod 0600`; PostgreSQL: `PGPASSWORD` env var; SQLite: no credentials needed.
- **Redis-backed semaphore** via `Cache::lock()` prevents dozens of simultaneous dumps.
- **State-machine enum** (`BackupStatus`) with explicit `canTransitionTo()` guards every model transition.
- **SIGTERM handling** with `pcntl_async_signals(true)` inside `RunBackupJob` — in-flight multipart uploads are aborted on graceful shutdown.
- **Queue `$timeout = 0`** because backup runtime is determined by the DB, not by the worker.

## Contracts / extension points

All of these are resolved from the container and can be swapped:

- `DatabaseDumper` — default resolved by `DumperFactory` based on config
- `DumperFactory` — singleton with `extend()` for custom drivers
- `CompressionDriver` — `PigzDriver` or `GzipDriver`
- `UploadDriver` — default is `S3MultipartUploader`
- `TenantResolver` — `ConfigTenantResolver` when `tenants` is populated, `SingleDatabaseResolver` otherwise
- `BackupStream` — chunked non-blocking stream abstraction

## Testing

```bash
composer install
./vendor/bin/phpunit
```

Unit tests cover:

- `DumperFactory` (driver resolution, auto-detection, extend API, error handling)
- `PostgreSQLDumper` (CLI args, PGPASSWORD env, password not in command)
- `SQLiteDumper` (database path, file validation, .dump invocation)
- `RetentionClassifier` (daily / weekly / monthly Sunday logic)
- `BackupPathBuilder` (tenant-scoped and `_global` paths)
- `BackupStatus` transitions (state-machine integrity)
- `ChecksumStream` (SHA-256 equivalence to `hash('sha256', $payload)`)
- `BackupSemaphore` (acquire / release / over-limit)

The feature test `StreamPipelineSmokeTest` is auto-skipped unless a dump tool + compressor are on `PATH` and `STREAM_BACKUP_TEST_*` env vars are set.

## License

MIT. See [`composer.json`](./composer.json) for author info.
