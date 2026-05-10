# Laravel Heavy Backup Package — MVP Technical Review

> **Reviewer's note:** This document covers every critical gap, technical issue, and missing piece I found in the MVP specification, ordered by severity. Each finding includes a concrete solution. The goal is not to tear the spec apart — the philosophy is sound and the scope control is good — but to surface decisions that must be made at spec time, not during implementation, because getting them wrong mid-build is expensive.

---

## Table of Contents

1. [Critical Gaps](#1-critical-gaps)
2. [Technical Issues](#2-technical-issues)
3. [Missing Pieces](#3-missing-pieces)
4. [Recommendations](#4-recommendations)

---

## 1. Critical Gaps

These are blockers. The spec cannot move to implementation without resolving them.

---

### 1.1 — The Pipe Deadlock Problem Is Named But Not Solved

**The spec says:**
> "The hardest part is stable stream piping between processes without deadlocks or memory buffering."

**The problem:**
The spec correctly identifies this as the biggest engineering risk, then provides zero solution. Naively piping three processes — `mysqldump → pigz → S3 multipart` — using PHP's `proc_open` with default blocking I/O **will deadlock** under buffer pressure. Here is what happens:

- `mysqldump` fills its stdout pipe buffer and blocks waiting for `pigz` to read.
- `pigz` fills its stdout pipe buffer and blocks waiting for the uploader to read.
- The uploader is blocked waiting for data from `pigz`.
- All three processes hang indefinitely. No error is thrown. The job times out silently.

This is not a theoretical edge case. It happens reliably on large databases the moment any intermediate buffer (default 64KB on Linux) fills up.

**The solution:**

Use `proc_open` with non-blocking streams and a select/read event loop. The pipeline reads from each process stdout only when data is available, never blocking on empty pipes.

```php
// StreamPipeline — non-blocking pipe loop
class StreamPipeline
{
    public function run(BackupContext $context): void
    {
        $dump = proc_open(
            $this->buildDumpCommand($context),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $dumpPipes
        );

        $compress = proc_open(
            $this->buildCompressCommand(),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $compressPipes
        );

        // Make all read ends non-blocking
        stream_set_blocking($dumpPipes[1], false);
        stream_set_blocking($compressPipes[1], false);

        $uploader = $this->uploader->initiate($context->metadata());
        $buffer   = '';

        while (true) {
            $read    = [$dumpPipes[1], $compressPipes[1]];
            $write   = null;
            $except  = null;

            // Block until at least one stream is readable — with 200ms timeout
            $changed = stream_select($read, $write, $except, 0, 200_000);

            if ($changed === false) {
                throw new PipelineException('stream_select failed');
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536); // 64 KB chunks

                if ($stream === $dumpPipes[1]) {
                    // Feed dump output into pigz stdin
                    fwrite($compressPipes[0], $chunk);
                }

                if ($stream === $compressPipes[1]) {
                    // Feed pigz output into multipart uploader
                    $uploader->writePart($chunk);
                }
            }

            if (feof($dumpPipes[1]) && feof($compressPipes[1])) {
                break;
            }
        }

        fclose($compressPipes[0]); // Signal EOF to pigz
        $uploader->complete();

        proc_close($dump);
        proc_close($compress);
    }
}
```

**Additional requirement:** The `pcntl` PHP extension must be installed for signal handling (see §1.3). This must be documented as a hard system requirement.

---

### 1.2 — PSR-7 `StreamInterface` Is the Wrong Abstraction

**The spec says:**
> All components exchange `StreamInterface` from PSR-7.

**The problem:**
PSR-7 `StreamInterface` was designed for HTTP request and response bodies. It carries assumptions that break in this context:

- It assumes synchronous, sequential reads over a seekable or at least stable byte source.
- It has no concept of backpressure or non-blocking reads.
- `getContents()` is part of the interface — calling it on a 100GB mysqldump stream loads everything into memory, exactly what the spec forbids.
- Symfony Process does not produce a PSR-7 stream. You would need an adapter layer whose seams become failure points.
- Guzzle's `PumpStream` is the closest match but it still doesn't handle non-blocking pipe reads correctly.

**The solution:**

Define a purpose-built `BackupStream` contract that models chunked, non-blocking reads from a live process pipe. Remove all PSR-7 `StreamInterface` references from the component interfaces.

```php
// src/Contracts/BackupStream.php
interface BackupStream
{
    /**
     * Read up to $length bytes. Returns empty string when no data is
     * currently available (non-blocking), null on EOF.
     */
    public function read(int $length = 65536): ?string;

    public function isEof(): bool;

    public function close(): void;
}
```

```php
// Updated component interfaces

interface DatabaseDumper
{
    public function dump(BackupContext $context): BackupStream;
}

interface CompressionDriver
{
    public function compress(BackupStream $input): BackupStream;
}

interface UploadDriver
{
    public function upload(BackupStream $stream, BackupMetadata $metadata): UploadResult;
}
```

This gives full control over chunked semantics, makes the non-blocking contract explicit, and avoids PSR-7 baggage entirely.

---

### 1.3 — Concurrency Enforcement Mechanism Is Undefined

**The spec says:**
> `max_concurrent: 2` in queue config.

**The problem:**
There is no spec for how this limit is actually enforced. The two obvious approaches both fail silently:

- Laravel's `withoutOverlapping()` works per-command, not across independently queued jobs.
- A database counter has race conditions under concurrent job dispatch.
- Doing nothing means all queued jobs start simultaneously, running 50 parallel mysqldump processes during a peak traffic period.

**The solution:**

Use a Redis atomic semaphore. Acquire a slot before the backup starts, release it in `finally` so it always runs even on failure or exception.

```php
// src/Support/BackupSemaphore.php
class BackupSemaphore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $maxConcurrent,
        private readonly string $key = 'heavy-backup:semaphore',
    ) {}

    public function acquire(int $timeoutSeconds = 3600): bool
    {
        return (bool) $this->cache->lock($this->key, $timeoutSeconds)
            ->block(0, function () {
                $active = (int) $this->cache->get($this->key . ':count', 0);

                if ($active >= $this->maxConcurrent) {
                    return false;
                }

                $this->cache->increment($this->key . ':count');
                return true;
            });
    }

    public function release(): void
    {
        $this->cache->decrement($this->key . ':count');
    }
}
```

```php
// Inside the backup job handle()
public function handle(BackupSemaphore $semaphore): void
{
    if (! $semaphore->acquire()) {
        // Re-queue with delay instead of dropping
        $this->release();
        static::dispatch($this->context)->delay(now()->addMinutes(5));
        return;
    }

    try {
        $this->pipeline->run($this->context);
    } finally {
        $semaphore->release();
    }
}
```

---

### 1.4 — Incomplete Multipart Upload Tracking

**The problem:**
S3 charges for incomplete multipart uploads that accumulate unfinished parts. The spec mentions "partial upload cleanup" but does not specify where the multipart `UploadId` is persisted. If a job crashes or the queue worker is killed after `createMultipartUpload` is called, the `UploadId` is lost and the incomplete upload will sit on S3 accumulating storage costs indefinitely with no way to abort it.

**The solution:**

Add an `upload_id` column to the `backups` table. Persist the `UploadId` immediately after it is obtained from S3, before any parts are uploaded. Add a scheduled cleanup job that aborts stale multipart uploads.

```sql
-- Add to backups migration
ALTER TABLE backups ADD COLUMN upload_id VARCHAR(255) NULL AFTER status;
ALTER TABLE backups ADD COLUMN parts_uploaded INT DEFAULT 0 AFTER upload_id;
```

```php
// UploadDriver saves UploadId before uploading parts
public function initiate(BackupMetadata $metadata): MultipartSession
{
    $result = $this->s3->createMultipartUpload([
        'Bucket' => $metadata->bucket,
        'Key'    => $metadata->path,
    ]);

    $uploadId = $result['UploadId'];

    // Persist immediately — before any part upload attempt
    Backup::whereKey($metadata->backupId)->update([
        'upload_id' => $uploadId,
        'status'    => BackupStatus::Uploading,
    ]);

    return new MultipartSession($uploadId, $metadata);
}
```

```php
// Cleanup job — aborts uploads stuck for more than 6 hours
class AbortStaleMultipartUploads implements ShouldQueue
{
    public function handle(): void
    {
        Backup::where('status', BackupStatus::Uploading)
            ->where('started_at', '<', now()->subHours(6))
            ->whereNotNull('upload_id')
            ->each(function (Backup $backup) {
                $this->s3->abortMultipartUpload([
                    'Bucket'   => $backup->disk_bucket,
                    'Key'      => $backup->path,
                    'UploadId' => $backup->upload_id,
                ]);

                $backup->update(['status' => BackupStatus::Aborted]);
            });
    }
}
```

---

## 2. Technical Issues

Serious issues that will cause problems in production if not addressed, but they do not block starting implementation.

---

### 2.1 — MySQL Credentials Are Exposed in Process List

**The problem:**
Any approach that passes MySQL credentials as CLI flags — `-u root -pSECRET` — exposes them in `ps aux` output. On a shared hosting environment or any server with multiple users, this is a credential leak.

**The solution:**

Write credentials to a temporary `.my.cnf` file, pass it via `--defaults-extra-file`, and delete it immediately after the process starts. The file descriptor is inherited by the child process but the file can be unlinked from the filesystem immediately.

```php
class MySQLCredentialFile
{
    private string $path;

    public function write(DatabaseCredentials $credentials): self
    {
        $this->path = tempnam(sys_get_temp_dir(), 'backup_cnf_');

        file_put_contents($this->path, sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $credentials->host,
            $credentials->port,
            $credentials->username,
            $credentials->password,
        ));

        chmod($this->path, 0600);

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function delete(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}
```

```php
// MySQLDumper — use --defaults-extra-file
$credFile = (new MySQLCredentialFile())->write($credentials);

try {
    $process = new Process([
        $this->binary,
        '--defaults-extra-file=' . $credFile->path(),
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        $context->databaseName,
    ]);

    $process->start();
} finally {
    $credFile->delete(); // Unlink from filesystem immediately
}
```

---

### 2.2 — Queue Timeout vs. Backup Duration

**The problem:**
For a 200GB database, a backup job can run for 3–6 hours. Laravel queue workers have a default `--timeout` of 60 seconds. When that timeout fires, the worker sends `SIGKILL` to the job process — no cleanup, no multipart abort, no status update. The backup is silently abandoned.

**The solution:**

The spec must explicitly document the required queue worker invocation and Supervisor configuration.

```bash
# Required queue worker invocation
php artisan queue:work redis \
    --queue=backups \
    --timeout=0 \
    --tries=3 \
    --sleep=3
```

```ini
; Required Supervisor configuration
[program:backup-worker]
command=php /var/www/artisan queue:work redis --queue=backups --timeout=0
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=86400       ; Must exceed longest expected backup duration
stopsignal=SIGTERM
killasgroup=true
stopasgroup=true
```

The job itself must implement `$timeout = 0` and handle `SIGTERM` gracefully:

```php
class RunTenantBackupJob implements ShouldQueue
{
    public int $timeout = 0;
    public int $tries   = 3;

    public function handle(): void
    {
        pcntl_signal(SIGTERM, function () {
            $this->context->backup->markAs(BackupStatus::Interrupted);
            $this->abortMultipartIfActive();
            exit(0);
        });

        pcntl_async_signals(true);

        $this->pipeline->run($this->context);
    }
}
```

---

### 2.3 — mysqldump STDERR Is Unaddressed

**The problem:**
mysqldump writes warnings, permission errors, and table-level failures to STDERR while still returning exit code 0. A backup job that silently skips tables due to permission issues will produce a corrupt-but-valid gzip file with no indication anything went wrong. This is the worst failure mode: you discover the problem only when you need to restore.

**The solution:**

Capture STDERR from the mysqldump process, treat non-empty STDERR as a warning, and treat any specific error patterns as a hard failure.

```php
class MySQLDumper implements DatabaseDumper
{
    public function dump(BackupContext $context): BackupStream
    {
        $process = new Process($this->buildCommand($context));
        $process->start();

        // Collect STDERR in the background while streaming stdout
        $stderrBuffer = '';
        $process->waitUntil(function ($type, $data) use (&$stderrBuffer) {
            if ($type === Process::ERR) {
                $stderrBuffer .= $data;
                Log::warning('mysqldump stderr', [
                    'database' => $this->context->databaseName,
                    'output'   => $data,
                ]);
            }
            return false; // Keep reading
        });

        return new ProcessBackupStream($process, $stderrBuffer);
    }
}

class ProcessBackupStream implements BackupStream
{
    public function close(): void
    {
        $exitCode = $this->process->getExitCode();

        if ($exitCode !== 0) {
            throw new DumpFailedException(
                "mysqldump exited with code {$exitCode}. STDERR: {$this->stderr}"
            );
        }

        if (str_contains($this->stderr, 'Error')) {
            throw new DumpPartialException(
                "mysqldump reported errors. STDERR: {$this->stderr}"
            );
        }
    }
}
```

---

### 2.4 — Retention Policy Classification Is Undefined

**The problem:**
The config defines `daily: 7, weekly: 4, monthly: 6` but there is no spec for how a backup is classified into these tiers. The classification logic directly determines what gets deleted during cleanup. Ambiguity here means the cleanup job will be implemented differently by different developers, producing unpredictable retention behavior.

**The solution:**

Define explicit promotion rules in the spec:

```
Daily   → every backup
Weekly  → the last successful backup of each Sunday
Monthly → the last successful backup of the last Sunday of each calendar month
```

```php
class RetentionClassifier
{
    public function classify(Backup $backup): RetentionTier
    {
        $date = $backup->finished_at;

        // Monthly: last Sunday of month
        if ($date->dayOfWeek === Carbon::SUNDAY) {
            $lastSundayOfMonth = $date->copy()->endOfMonth()->previous(Carbon::SUNDAY);
            if ($date->isSameDay($lastSundayOfMonth)) {
                return RetentionTier::Monthly;
            }
        }

        // Weekly: any other Sunday
        if ($date->dayOfWeek === Carbon::SUNDAY) {
            return RetentionTier::Weekly;
        }

        // Daily: everything else
        return RetentionTier::Daily;
    }
}
```

```php
class BackupCleanupJob implements ShouldQueue
{
    public function handle(RetentionClassifier $classifier): void
    {
        Backup::successful()->each(function (Backup $backup) use ($classifier) {
            $tier  = $classifier->classify($backup);
            $limit = config("backup.retention.{$tier->value}");

            $rank = Backup::successful()
                ->where('tenant_id', $backup->tenant_id)
                ->where('retention_tier', $tier)
                ->where('finished_at', '>=', $backup->finished_at)
                ->count();

            if ($rank > $limit) {
                $this->storage->delete($backup->path);
                $backup->delete();
            }
        });
    }
}
```

---

### 2.5 — Checksum Computation Timing Is Unresolved

**The problem:**
The `backups` table has a `checksum` column but the spec says nothing about when or how it is computed. There are three options, each with a different trade-off:

- **Option A:** Hash the raw SQL stream before compression → verifies data integrity but requires a second read pass or a tee.
- **Option B:** Hash the compressed stream during upload → verifiable without re-downloading, but does not verify the SQL is valid.
- **Option C:** Download and hash after upload → verifies the stored object but doubles transfer costs.

**The solution:**

Use Option B with a stream tee: compute SHA256 on the compressed bytes as they pass through to the uploader, with zero extra memory. Write the checksum to the `backups` record after upload completes.

```php
class ChecksumStream implements BackupStream
{
    private HashContext $hash;

    public function __construct(private readonly BackupStream $inner)
    {
        $this->hash = hash_init('sha256');
    }

    public function read(int $length = 65536): ?string
    {
        $chunk = $this->inner->read($length);

        if ($chunk !== null && $chunk !== '') {
            hash_update($this->hash, $chunk);
        }

        return $chunk;
    }

    public function checksum(): string
    {
        return hash_final($this->hash);
    }

    public function isEof(): bool { return $this->inner->isEof(); }
    public function close(): void { $this->inner->close(); }
}
```

```php
// In StreamPipeline::run()
$checksumStream = new ChecksumStream($compressedStream);
$result = $this->uploader->upload($checksumStream, $metadata);

$backup->update(['checksum' => $checksumStream->checksum()]);
```

---

## 3. Missing Pieces

Pieces that are required for the package to be complete and installable, but are not mentioned anywhere in the spec.

---

### 3.1 — `BackupContext` and `BackupMetadata` DTOs Are Undefined

Both are used in every interface signature but never defined. These are the core data carriers for the entire pipeline.

**Solution:**

```php
// src/DTOs/BackupContext.php
final class BackupContext
{
    public function __construct(
        public readonly int        $backupId,
        public readonly int|string $tenantId,
        public readonly string     $databaseName,
        public readonly string     $connectionName,
        public readonly string     $disk,
        public readonly string     $storagePath,
        public readonly int        $timeoutSeconds = 0,
        public readonly array      $extraDumpFlags = [],
    ) {}
}

// src/DTOs/BackupMetadata.php
final class BackupMetadata
{
    public function __construct(
        public readonly int        $backupId,
        public readonly int|string $tenantId,
        public readonly string     $bucket,
        public readonly string     $path,
        public readonly string     $disk,
        public readonly Carbon     $startedAt,
    ) {}
}

// src/DTOs/UploadResult.php
final class UploadResult
{
    public function __construct(
        public readonly string $eTag,
        public readonly string $location,
        public readonly int    $sizeBytes,
        public readonly float  $durationSeconds,
    ) {}
}
```

---

### 3.2 — Multi-Tenant Discovery Strategy

`backup:all` is specced as an Artisan command but there is no mechanism for discovering which tenants exist. The package cannot know whether tenants come from a `tenants` table, a config array, Spatie Multitenancy, Tenancy for Laravel, or something custom.

**Solution:**

Define a `TenantResolver` contract the host application binds in its own `AppServiceProvider`. The package's `backup:all` command depends on the contract, not on any concrete implementation.

```php
// src/Contracts/TenantResolver.php
interface TenantResolver
{
    /**
     * Return an iterable of BackupContext — one per tenant database.
     * Yielding is preferred over returning a full collection for large tenant counts.
     *
     * @return iterable<BackupContext>
     */
    public function resolve(): iterable;
}
```

```php
// Host app — example implementation using Spatie Multitenancy
class SpatieMultitenantResolver implements TenantResolver
{
    public function resolve(): iterable
    {
        return Tenant::all()->map(fn (Tenant $tenant) => new BackupContext(
            backupId:       0, // assigned after Backup record creation
            tenantId:       $tenant->id,
            databaseName:   $tenant->database,
            connectionName: 'tenant',
            disk:           config('backup.default_disk'),
            storagePath:    "tenants/{$tenant->id}/" . now()->format('Y/m/d'),
        ));
    }
}
```

```php
// Host app service provider
$this->app->bind(TenantResolver::class, SpatieMultitenantResolver::class);
```

---

### 3.3 — Backup File Naming Convention

No naming convention is defined. The retention policy, restore tooling, and monitoring all depend on predictable, parseable file paths. Without a spec, every developer implementing the package will invent their own convention.

**Solution:**

Define the path scheme explicitly, with a dedicated builder class.

```
Pattern: {tenant_id}/{year}/{month}/{day}/{database}-{timestamp}.sql.gz
Example: tenant_42/2026/05/10/company_db-20260510T143022Z.sql.gz
```

```php
// src/Support/BackupPathBuilder.php
class BackupPathBuilder
{
    public function build(BackupContext $context, Carbon $at = null): string
    {
        $at ??= now();

        return implode('/', [
            $context->tenantId,
            $at->format('Y'),
            $at->format('m'),
            $at->format('d'),
            sprintf('%s-%sZ.sql.gz', $context->databaseName, $at->format('Ymd\THis')),
        ]);
    }
}
```

---

### 3.4 — Backup Status State Machine

The `status` column exists in the database schema but valid states and transitions are not defined. Without a state machine, retry logic, monitoring alerts, and cleanup jobs cannot be implemented consistently.

**Solution:**

Define states and valid transitions explicitly.

```
States:     pending → dumping → compressing → uploading → verifying → completed
                                                                    ↘ failed
                                                    ↘ aborted (SIGTERM received)
                                        ↘ timed_out
```

```php
// src/Enums/BackupStatus.php
enum BackupStatus: string
{
    case Pending      = 'pending';
    case Dumping      = 'dumping';
    case Compressing  = 'compressing';
    case Uploading    = 'uploading';
    case Verifying    = 'verifying';
    case Completed    = 'completed';
    case Failed       = 'failed';
    case Aborted      = 'aborted';
    case TimedOut     = 'timed_out';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending     => $next === self::Dumping,
            self::Dumping     => in_array($next, [self::Compressing, self::Failed, self::Aborted]),
            self::Compressing => in_array($next, [self::Uploading,   self::Failed, self::Aborted]),
            self::Uploading   => in_array($next, [self::Verifying,   self::Failed, self::Aborted, self::TimedOut]),
            self::Verifying   => in_array($next, [self::Completed,   self::Failed]),
            default           => false,
        };
    }
}
```

---

### 3.5 — Binary Path Resolution

The config exposes `binary: 'mysqldump'` but does not define how the binary is located on different environments (Herd, Docker, cPanel, Homebrew MySQL, custom paths).

**Solution:**

Add a `BinaryLocator` that resolves the binary path with clear precedence and a helpful exception when not found.

```php
// src/Support/BinaryLocator.php
class BinaryLocator
{
    public function locate(string $name): string
    {
        // 1. Explicit config path
        $configured = config("backup.dump.binary");
        if ($configured && $configured !== $name && file_exists($configured)) {
            return $configured;
        }

        // 2. PATH lookup via which/where
        $process = new Process(['which', $name]);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        // 3. Common installation paths
        $candidates = [
            "/usr/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/opt/homebrew/bin/{$name}",
            "/opt/homebrew/opt/mysql-client/bin/{$name}",
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        throw new BinaryNotFoundException(
            "Cannot locate '{$name}'. Install it or set 'backup.dump.binary' in your config to the full path."
        );
    }
}
```

---

### 3.6 — `backups` Table Is Missing Columns

The spec defines a table schema that is missing columns required by solutions to the above gaps.

**Updated full migration:**

```php
Schema::create('backups', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->nullable()->index();
    $table->string('database_name');
    $table->string('disk');
    $table->string('path')->nullable();
    $table->string('status')->default('pending');
    $table->string('retention_tier')->nullable();    // daily | weekly | monthly
    $table->string('upload_id')->nullable();         // S3 multipart UploadId
    $table->unsignedInteger('parts_uploaded')->default(0);
    $table->unsignedBigInteger('size')->nullable();  // bytes
    $table->string('checksum')->nullable();          // sha256 hex
    $table->string('compression_driver')->nullable();
    $table->float('compression_ratio')->nullable();
    $table->float('upload_speed_mbps')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->unsignedInteger('duration')->nullable(); // seconds
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index(['status', 'started_at']); // for stale upload cleanup
    $table->index(['retention_tier', 'finished_at']); // for cleanup job
});
```

---

### 3.7 — Service Provider and Package Wiring

The spec never defines what the `ServiceProvider` registers. Without this, the package cannot be installed.

**Solution — define `HeavyBackupServiceProvider` explicitly:**

```php
class HeavyBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/backup.php', 'backup');

        // Core bindings
        $this->app->singleton(BackupManager::class);
        $this->app->singleton(BinaryLocator::class);
        $this->app->singleton(BackupSemaphore::class);
        $this->app->singleton(BackupPathBuilder::class);
        $this->app->singleton(RetentionClassifier::class);

        // Compression driver
        $this->app->bind(CompressionDriver::class, function ($app) {
            return match (config('backup.compression.driver')) {
                'pigz'  => new PigzDriver($app->make(BinaryLocator::class)),
                'gzip'  => new GzipDriver($app->make(BinaryLocator::class)),
                default => throw new InvalidConfigException('Unknown compression driver'),
            };
        });

        // Database dumper
        $this->app->bind(DatabaseDumper::class, function ($app) {
            return new MySQLDumper(
                locator:     $app->make(BinaryLocator::class),
                credentials: $app->make(MySQLCredentialFile::class),
            );
        });

        // Upload driver
        $this->app->bind(UploadDriver::class, S3MultipartUploader::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/backup.php' => config_path('backup.php'),
            ], 'backup-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                BackupTenantCommand::class,
                BackupAllCommand::class,
                BackupCleanupCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('backup.auto_schedule')) {
                $schedule->job(new AbortStaleMultipartUploads)->hourly();
            }
        });
    }
}
```

---

## 4. Recommendations

Ordered by impact. These do not block the build but will make the difference between a reliable package and a fragile one.

---

### 4.1 — Prototype the Pipe Loop Before Writing Any Laravel Code

The entire package depends on §1.1 being stable. Build a standalone PHP CLI script — no Laravel, no abstractions — that runs:

```bash
mysqldump | pigz | upload-to-s3
```

using `proc_open` with non-blocking streams and `stream_select`. Run it against a real 10GB database. Confirm memory stays below 50MB. Confirm it does not deadlock. Confirm the uploaded file is a valid gzip. Only then write the first Laravel class.

This prototype will also surface OS-level issues: pipe buffer sizes, pigz CPU behavior, S3 SDK chunking behavior, and PHP stream wrapper edge cases.

---

### 4.2 — Add a Post-Upload Verification Step

After upload completes, add a `verifying` state that confirms the backup is usable. At minimum:

```php
class BackupVerifier
{
    public function verify(Backup $backup): VerificationResult
    {
        // 1. Confirm S3 object exists and size matches
        $head = $this->s3->headObject([
            'Bucket' => $backup->disk_bucket,
            'Key'    => $backup->path,
        ]);

        if ((int) $head['ContentLength'] !== $backup->size) {
            return VerificationResult::failed('Size mismatch');
        }

        // 2. Stream the first 1MB and test gzip header validity
        $stream = $this->s3->getObject([
            'Bucket' => $backup->disk_bucket,
            'Key'    => $backup->path,
            'Range'  => 'bytes=0-1048575',
        ])['Body'];

        $header = $stream->read(2);

        // Gzip magic bytes: 0x1f 0x8b
        if ($header !== "\x1f\x8b") {
            return VerificationResult::failed('Invalid gzip header — file may be corrupt');
        }

        return VerificationResult::passed();
    }
}
```

Silent backup corruption is the worst failure mode in a backup system. Detecting it right after upload means you can immediately retry rather than discovering it during a production incident.

---

### 4.3 — Define Queue Worker Requirements in the README

Document the exact worker configuration required for this package. Most production backup failures I have seen are caused by a worker with `--timeout=60` killing a 4-hour backup job. Make this impossible to miss:

```markdown
## Queue Configuration

This package runs long-lived jobs. Standard queue worker timeouts will kill them.

**Required:**
php artisan queue:work redis --queue=backups --timeout=0

**Supervisor stopwaitsecs must exceed your longest backup:**
stopwaitsecs=86400  ; 24 hours

**Required PHP extension:**
ext-pcntl  ; for signal handling and graceful shutdown
```

---

### 4.4 — Add S3 Lifecycle Policy to Docs

Document a recommended S3 lifecycle rule to automatically abort incomplete multipart uploads after 24 hours. This is a safety net independent of the application-level cleanup:

```json
{
  "Rules": [{
    "ID": "abort-incomplete-multipart",
    "Status": "Enabled",
    "Filter": { "Prefix": "" },
    "AbortIncompleteMultipartUpload": {
      "DaysAfterInitiation": 1
    }
  }]
}
```

This costs nothing and prevents runaway storage bills if the application-level cleanup ever fails.

---

### 4.5 — Use Compression Level 4 Instead of 6 for Large Databases

The spec defaults to `level: 6`. On databases above 50GB, level 6 can make compression the bottleneck in the pipeline — slower than mysqldump and slower than the S3 upload, which means pigz becomes the choke point and the pipeline backs up.

Level 4 with pigz gives roughly 80% of the compression ratio at 50% of the CPU cost. The storage savings difference between level 4 and level 6 rarely justifies the time cost for databases in the tens-of-gigabytes range. Make this configurable and change the default to 4:

```php
'compression' => [
    'driver' => 'pigz',
    'level'  => 4, // Recommended for databases > 10GB
],
```

---

*End of review.*
