# Laravel Heavy Backup Package — MVP Specification

## Overview

The goal of this package is to provide a production-grade backup solution for large Laravel applications with heavy databases and multi-tenant architectures.

The package is designed specifically for:

- Large MySQL/MariaDB databases
- Multi-company SaaS systems
- Streaming-based backups
- Minimal memory usage
- Minimal disk usage
- Direct cloud uploads
- Long-running CLI processes
- S3-compatible storage providers

The package should support databases ranging from a few gigabytes up to tens or hundreds of gigabytes.

---

# Core Philosophy

The package must avoid traditional backup approaches that generate huge temporary files or ZIP archives.

## The package MUST NOT:

- Generate full SQL dump files locally
- Create large ZIP archives
- Load entire backup contents into memory
- Depend on HTTP request lifecycle
- Use file_get_contents() for large files
- Store backups permanently on production servers
- Depend on synchronous web requests

## The package MUST:

- Stream database dumps directly
- Compress while streaming
- Upload while streaming
- Support multipart uploads
- Minimize memory usage
- Minimize disk usage
- Be queue-friendly
- Support retries
- Support retention policies
- Support monitoring and logging

---

# MVP Scope

The MVP should remain intentionally focused.

## Included Features

### Database Backups

- MySQL support
- MariaDB support
- Multi-tenant database support
- Streaming dumps
- Compression support
- Direct cloud uploads

### Storage

- S3-compatible storage support
- DigitalOcean Spaces support
- Multipart uploads

### Queue Integration

- Laravel queue support
- Concurrent job control
- Retry handling

### Monitoring

- Backup status tracking
- Duration tracking
- File size tracking
- Error logging

### Cleanup

- Retention policy support
- Automated cleanup jobs

---

# Features Excluded From MVP

The following features should be postponed until later phases:

- File backups
- UI dashboard
- Backup encryption
- PostgreSQL support
- Incremental backups
- Binary log backups
- Point-in-time recovery
- Restore orchestration
- Snapshot orchestration
- Kubernetes support
- Distributed backup workers

---

# High-Level Architecture

```text
Laravel Scheduler
    ↓
Backup Dispatcher
    ↓
Queued Backup Jobs
    ↓
Streaming Pipeline
    ↓
Compression Layer
    ↓
Multipart Upload
    ↓
DigitalOcean Spaces / S3
```

---

# Internal Components

## Backup Manager

Responsible for:

- Reading configuration
- Dispatching jobs
- Managing retention policies
- Managing orchestration

### Example

```php
BackupManager::backup($tenant);
```

---

## Database Dumper

Responsible for generating database dump streams.

### Interface

```php
interface DatabaseDumper
{
    public function dump(BackupContext $context): StreamInterface;
}
```

### MVP Implementation

- MySQLDumper

### Internally Uses

```bash
mysqldump --single-transaction --quick --skip-lock-tables
```

---

## Compression Layer

Responsible for compressing streams without creating temporary files.

### Interface

```php
interface CompressionDriver
{
    public function compress(StreamInterface $stream): StreamInterface;
}
```

### MVP Drivers

- pigz
- gzip fallback

---

## Upload Driver

Responsible for uploading compressed streams to cloud storage.

### Interface

```php
interface UploadDriver
{
    public function upload(
        StreamInterface $stream,
        BackupMetadata $metadata
    ): UploadResult;
}
```

### MVP Implementation

- S3 multipart uploader

---

## Pipeline Engine

The pipeline engine is the heart of the package.

It connects:

```text
mysqldump stdout
    ↓
compression stdin/stdout
    ↓
multipart uploader
```

without creating intermediate files.

---

# Recommended Technical Stack

| Layer | Technology |
|---|---|
| Framework | Laravel |
| Runtime | PHP CLI |
| Queue | Redis |
| Streaming | Symfony Process |
| Compression | pigz |
| Uploads | AWS SDK Multipart Upload |
| Storage | DigitalOcean Spaces |

---

# Process Management

Use Symfony Process component for:

- Process execution
- Stream handling
- Timeout control
- STDERR handling
- Long-running CLI support

---

# Upload Layer

Use AWS SDK for PHP.

Important requirements:

- Must use MultipartUploader
- Must support S3-compatible providers
- Must support streaming uploads
- Must avoid loading entire files into memory

---

# Backup Flow

## Step 1 — Create Database Dump Stream

```bash
mysqldump \
 --single-transaction \
 --quick \
 --skip-lock-tables
```

### Important Flags

| Flag | Purpose |
|---|---|
| --single-transaction | Avoids table locking |
| --quick | Streams rows directly |
| --skip-lock-tables | Prevents blocking production traffic |

---

## Step 2 — Compress Stream

Preferred:

```bash
pigz
```

Fallback:

```bash
gzip
```

---

## Step 3 — Upload Stream

Use multipart uploads directly to S3-compatible storage.

The system should stream directly from compression into upload.

No local files should be generated.

---

# Configuration Structure

```php
return [

    'default_disk' => 'spaces',

    'compression' => [
        'driver' => 'pigz',
        'level' => 6,
    ],

    'dump' => [
        'binary' => 'mysqldump',
        'timeout' => 0,
    ],

    'multipart' => [
        'part_size' => 50 * 1024 * 1024,
    ],

    'retention' => [
        'daily' => 7,
        'weekly' => 4,
        'monthly' => 6,
    ],

    'queue' => [
        'connection' => 'redis',
        'queue' => 'backups',
        'max_concurrent' => 2,
    ],

];
```

---

# Database Schema

## backups Table

```text
id
tenant_id
database_name
disk
path
status
size
started_at
finished_at
duration
error_message
checksum
created_at
updated_at
```

---

# Queue Strategy

The package must avoid running many backups simultaneously.

Recommended concurrency:

```text
1–3 concurrent backups maximum
```

Reasons:

- Protect database performance
- Reduce CPU pressure
- Reduce disk IO pressure
- Prevent server instability

---

# Failure Handling

The package must support:

- Process crash detection
- Upload failure handling
- Retry support
- Timeout handling
- Multipart cleanup handling
- Partial upload cleanup

---

# Suggested Project Structure

```text
src/
 ├── Contracts/
 ├── Dumpers/
 ├── Compression/
 ├── Uploaders/
 ├── Pipelines/
 ├── Jobs/
 ├── DTOs/
 ├── Exceptions/
 └── Support/
```

---

# Critical Class — StreamPipeline

The StreamPipeline class is responsible for connecting:

- Database dump stream
- Compression stream
- Upload stream

### Example Flow

```php
$dumpStream = $dumper->dump();

$compressed = $compressor->compress($dumpStream);

$uploader->upload($compressed);
```

This component must be carefully designed to avoid:

- Deadlocks
- Memory buffering
- Broken pipes
- Stream exhaustion

---

# Memory Design Rules

The package must:

- Never concatenate streams
- Never read full contents into memory
- Never call getContents() on huge streams
- Always process chunked streams
- Always process incremental reads

### Target Memory Usage

```text
< 50MB RAM usage
```

even for very large databases.

---

# Monitoring Requirements

The package should track:

- Backup speed
- Compression ratio
- Upload speed
- Total duration
- Estimated completion time
- Backup sizes
- Failure reasons

---

# Metadata Files

Each backup should generate metadata.

### Example

```json
{
  "database": "company_14",
  "compressed": true,
  "created_at": "2026-05-10",
  "size": 123456789,
  "checksum": "sha256..."
}
```

---

# Restore Philosophy

The MVP should support CLI-only restores.

### Example

```bash
gunzip < backup.sql.gz | mysql database
```

Restore orchestration should be postponed until later phases.

---

# Laravel Integration

## Scheduler

```php
$schedule
    ->command('backup:tenants')
    ->hourly();
```

---

# Suggested Artisan Commands

## Backup Single Tenant

```bash
php artisan backup:tenant {tenant}
```

## Backup All Tenants

```bash
php artisan backup:all
```

## Cleanup Old Backups

```bash
php artisan backup:cleanup
```

---

# MVP Priorities

## Priority 1

Reliable streaming pipeline.

## Priority 2

Multipart uploads.

## Priority 3

Queue orchestration.

## Priority 4

Retention cleanup.

Everything else should come later.

---

# Future Roadmap

## Phase 2

- Encryption
- PostgreSQL support
- Restore commands
- Dashboard UI

## Phase 3

- Incremental backups
- Binary log backups
- Point-in-time recovery
- Deduplication

## Phase 4

- Distributed workers
- Backup agents
- Kubernetes support
- Multi-region replication

---

# Biggest Engineering Risk

The hardest part of the system is not uploads.

The hardest part is:

```text
Stable stream piping between processes
without deadlocks or memory buffering.
```

This area must be carefully designed and heavily tested.

---

# Final Recommendation

The MVP should focus entirely on building a stable, reliable streaming pipeline before introducing advanced enterprise backup features.

A stable streaming foundation will allow the package to scale safely for:

- Multi-tenant SaaS systems
- Massive databases
- Enterprise Laravel applications
- Cloud-native backup architectures

without introducing unnecessary infrastructure complexity.

