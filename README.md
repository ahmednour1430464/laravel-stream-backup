# laravel-stream-backup

> Streaming MySQL → pigz → S3 multipart backups for Laravel 10 & 11, with **constant memory use** regardless of database size.

`mysqldump` is piped to `pigz` which is piped directly into S3 multipart uploads. Nothing is ever buffered to disk and nothing exceeds the 32 MB part buffer in RAM, so a 300 GB database and a 3 GB database use roughly the same amount of memory.

This package is the productised form of the proof-of-concept script [`backup.php`](./backup.php).

---

## Requirements

- PHP `^8.1` with the `pcntl` and `hash` extensions (PHP `^8.2` required when running on Laravel 11)
- Laravel `^10.0` or `^11.0`
- `mysqldump` on `PATH`
- `pigz` on `PATH` (falls back to built-in `gzip` driver if unavailable)
- An S3-compatible bucket (AWS S3, DigitalOcean Spaces, MinIO, ...)

### Compatibility matrix

| Package | PHP | Laravel | Testbench | PHPUnit |
|---|---|---|---|---|
| `ahmednour1430464/laravel-stream-backup` | `8.1 / 8.2 / 8.3` | `10.*` | `8.*` | `10.*` |
| `ahmednour1430464/laravel-stream-backup` | `8.2 / 8.3` | `11.*` | `9.*` | `11.*` |

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
```

### DigitalOcean Spaces compatibility

The bundled S3 client is configured with:

```php
'request_checksum_calculation' => 'when_required',
'response_checksum_validation' => 'when_required',
```

These two flags are **mandatory** for Spaces — without them `completeMultipartUpload` fails with `MalformedXML` because Spaces does not implement the SDK's new default checksum headers.

## Usage

### Single database

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
],
```

Then:

```bash
php artisan backup:all                     # enqueue every tenant
php artisan backup:tenant 1                # single tenant by id
php artisan backup:cleanup                 # apply retention policy
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

```
mysqldump --single-transaction
   │ stdout (non-blocking)
   ▼
  pigz -4
   │ stdout (non-blocking)
   ▼
 ChecksumStream (SHA-256 tee)
   │
   ▼
 S3 multipart (32 MB parts, php://temp buffer)
```

Key design points carried over from the review doc:

- **Write-side `stream_select`** with a `$pendingChunk` buffer — never busy-waits on blocked pipes.
- **Exit-code validation before `completeMultipartUpload`** — refuses to commit objects when `mysqldump` or `pigz` exited non-zero or printed recognisable errors to stderr.
- **`php://temp` part buffer** — zero copy, spills to disk past 2 MB.
- **`MySQLCredentialFile`** writes `~/.mysql-credentials-<uniqid>`, `chmod 0600`, unlinked in `register_shutdown_function` so a `kill -9` still cleans up.
- **Redis-backed semaphore** via `Cache::lock()` prevents dozens of simultaneous dumps.
- **State-machine enum** (`BackupStatus`) with explicit `canTransitionTo()` guards every model transition.
- **SIGTERM handling** with `pcntl_async_signals(true)` inside `RunBackupJob` — in-flight multipart uploads are aborted on graceful shutdown.
- **Queue `$timeout = 0`** because backup runtime is determined by the DB, not by the worker.

## Contracts / extension points

All of these are resolved from the container and can be swapped:

- `BackupStream` — chunked non-blocking stream abstraction
- `DatabaseDumper` — default is `MySQLDumper`
- `CompressionDriver` — `PigzDriver` or `GzipDriver`
- `UploadDriver` — default is `S3MultipartUploader`
- `TenantResolver` — `ConfigTenantResolver` when `tenants` is populated, `SingleDatabaseResolver` otherwise

## Testing

```bash
composer install
./vendor/bin/phpunit
```

Unit tests cover:

- `RetentionClassifier` (daily / weekly / monthly Sunday logic)
- `BackupPathBuilder` (tenant-scoped and `_global` paths)
- `BackupStatus` transitions (state-machine integrity)
- `ChecksumStream` (SHA-256 equivalence to `hash('sha256', $payload)`)
- `BackupSemaphore` (acquire / release / over-limit)

The feature test `StreamPipelineSmokeTest` is auto-skipped unless `mysqldump` + `pigz` are on `PATH` and `STREAM_BACKUP_TEST_*` env vars are set.

## License

MIT. See [`composer.json`](./composer.json) for author info.
