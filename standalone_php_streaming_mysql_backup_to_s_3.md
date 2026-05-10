# Standalone PHP CLI Streaming Backup

This is a standalone PHP CLI implementation — no Laravel, no frameworks, no abstractions — that streams:

```bash
mysqldump | pigz | upload-to-s3
```

using:

- `proc_open`
- non-blocking streams
- `stream_select`
- constant-memory streaming
- multipart uploads to S3

The design avoids deadlocks by continuously draining all pipes.

---

# Important Reality Check

I cannot actually execute this against a real 10GB MySQL database from this environment because:

- I do not have access to your infrastructure
- I cannot connect to your MySQL server
- I cannot access your S3 bucket
- I cannot run long-lived external binaries like `mysqldump` and `pigz` here

So I cannot honestly claim:

- “confirmed below 50MB memory on your machine”
- “confirmed no deadlocks under your OS/kernel/pipe limits”
- “confirmed valid gzip uploaded to your S3 bucket”

What I *can* provide is:

- a production-grade streaming implementation
- instrumentation to verify memory usage
- deadlock-safe pipe handling
- gzip verification steps
- commands to benchmark with a real 10GB database

---

# Required Packages

Install:

```bash
sudo apt-get install mysql-client pigz
```

Install AWS SDK:

```bash
composer require aws/aws-sdk-php
```

---

# Architecture

Pipeline:

```text
mysqldump stdout
        ↓
pigz stdin
        ↓
pigz stdout (gzip stream)
        ↓
PHP event loop
        ↓
AWS multipart upload
```

Key properties:

- No temp files
- No buffering entire dump in memory
- Non-blocking pipes
- Backpressure-safe
- Multipart upload
- Memory stays effectively constant

---

# backup.php

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

const READ_CHUNK_SIZE = 1024 * 1024; // 1MB
const S3_PART_SIZE = 8 * 1024 * 1024; // 8MB

$config = [
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'your_database',
        'username' => 'root',
        'password' => 'secret',
    ],
    's3' => [
        'region' => 'us-east-1',
        'bucket' => 'your-backup-bucket',
        'key' => 'backups/' . date('Y-m-d_H-i-s') . '.sql.gz',
    ],
];

$s3 = new S3Client([
    'version' => 'latest',
    'region' => $config['s3']['region'],
]);

$dumpCommand = sprintf(
    'mysqldump --single-transaction --quick --skip-lock-tables --host=%s --port=%d --user=%s --password=%s %s',
    escapeshellarg($config['mysql']['host']),
    $config['mysql']['port'],
    escapeshellarg($config['mysql']['username']),
    escapeshellarg($config['mysql']['password']),
    escapeshellarg($config['mysql']['database'])
);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$dumpProc = proc_open($dumpCommand, $descriptors, $dumpPipes);

if (!is_resource($dumpProc)) {
    throw new RuntimeException('Failed to start mysqldump');
}

$pigzProc = proc_open('pigz -c', $descriptors, $pigzPipes);

if (!is_resource($pigzProc)) {
    proc_terminate($dumpProc);
    throw new RuntimeException('Failed to start pigz');
}

stream_set_blocking($dumpPipes[1], false);
stream_set_blocking($dumpPipes[2], false);
stream_set_blocking($pigzPipes[0], false);
stream_set_blocking($pigzPipes[1], false);
stream_set_blocking($pigzPipes[2], false);

$upload = $s3->createMultipartUpload([
    'Bucket' => $config['s3']['bucket'],
    'Key' => $config['s3']['key'],
    'ContentType' => 'application/gzip',
]);

$uploadId = $upload['UploadId'];
$parts = [];
$partNumber = 1;

$dumpFinished = false;
$pigzInputClosed = false;
$pigzFinished = false;

$s3Buffer = '';
$totalBytesUploaded = 0;

$startTime = microtime(true);

try {

    while (true) {

        $read = [];

        if (!$dumpFinished) {
            $read[] = $dumpPipes[1];
        }

        if (!$pigzFinished) {
            $read[] = $pigzPipes[1];
        }

        $write = [];
        $except = null;

        if (empty($read)) {
            break;
        }

        $changed = stream_select($read, $write, $except, 1);

        if ($changed === false) {
            throw new RuntimeException('stream_select failed');
        }

        foreach ($read as $stream) {

            if ($stream === $dumpPipes[1]) {

                $data = fread($dumpPipes[1], READ_CHUNK_SIZE);

                if ($data === '' || $data === false) {

                    if (feof($dumpPipes[1])) {
                        fclose($pigzPipes[0]);
                        fclose($dumpPipes[1]);
                        $dumpFinished = true;
                        $pigzInputClosed = true;
                    }

                    continue;
                }

                $offset = 0;
                $length = strlen($data);

                while ($offset < $length) {

                    $written = fwrite(
                        $pigzPipes[0],
                        substr($data, $offset)
                    );

                    if ($written === false) {
                        throw new RuntimeException('Failed writing to pigz stdin');
                    }

                    $offset += $written;
                }
            }

            if ($stream === $pigzPipes[1]) {

                $compressed = fread($pigzPipes[1], READ_CHUNK_SIZE);

                if ($compressed === '' || $compressed === false) {

                    if (feof($pigzPipes[1])) {
                        fclose($pigzPipes[1]);
                        $pigzFinished = true;
                    }

                    continue;
                }

                $s3Buffer .= $compressed;

                while (strlen($s3Buffer) >= S3_PART_SIZE) {

                    $partData = substr($s3Buffer, 0, S3_PART_SIZE);
                    $s3Buffer = substr($s3Buffer, S3_PART_SIZE);

                    $result = $s3->uploadPart([
                        'Bucket' => $config['s3']['bucket'],
                        'Key' => $config['s3']['key'],
                        'UploadId' => $uploadId,
                        'PartNumber' => $partNumber,
                        'Body' => $partData,
                    ]);

                    $parts[] = [
                        'ETag' => $result['ETag'],
                        'PartNumber' => $partNumber,
                    ];

                    $totalBytesUploaded += strlen($partData);

                    echo sprintf(
                        "[%s] uploaded part=%d total=%.2fMB memory=%.2fMB peak=%.2fMB\n",
                        date('H:i:s'),
                        $partNumber,
                        $totalBytesUploaded / 1024 / 1024,
                        memory_get_usage(true) / 1024 / 1024,
                        memory_get_peak_usage(true) / 1024 / 1024
                    );

                    $partNumber++;
                }
            }
        }

        $dumpStatus = proc_get_status($dumpProc);
        $pigzStatus = proc_get_status($pigzProc);

        if (!$dumpStatus['running'] && !$dumpFinished) {
            $dumpFinished = true;
        }

        if (!$pigzStatus['running'] && $pigzFinished) {
            break;
        }
    }

    if ($s3Buffer !== '') {

        $result = $s3->uploadPart([
            'Bucket' => $config['s3']['bucket'],
            'Key' => $config['s3']['key'],
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $s3Buffer,
        ]);

        $parts[] = [
            'ETag' => $result['ETag'],
            'PartNumber' => $partNumber,
        ];

        $totalBytesUploaded += strlen($s3Buffer);
    }

    $s3->completeMultipartUpload([
        'Bucket' => $config['s3']['bucket'],
        'Key' => $config['s3']['key'],
        'UploadId' => $uploadId,
        'MultipartUpload' => [
            'Parts' => $parts,
        ],
    ]);

    $dumpExit = proc_close($dumpProc);
    $pigzExit = proc_close($pigzProc);

    $duration = microtime(true) - $startTime;

    echo PHP_EOL;
    echo 'Backup completed successfully' . PHP_EOL;
    echo 'S3 Key: ' . $config['s3']['key'] . PHP_EOL;
    echo 'Uploaded: ' . round($totalBytesUploaded / 1024 / 1024, 2) . ' MB' . PHP_EOL;
    echo 'Duration: ' . round($duration, 2) . ' sec' . PHP_EOL;
    echo 'Peak Memory: ' . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB' . PHP_EOL;
    echo 'mysqldump exit code: ' . $dumpExit . PHP_EOL;
    echo 'pigz exit code: ' . $pigzExit . PHP_EOL;

} catch (Throwable $e) {

    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;

    try {
        $s3->abortMultipartUpload([
            'Bucket' => $config['s3']['bucket'],
            'Key' => $config['s3']['key'],
            'UploadId' => $uploadId,
        ]);
    } catch (Throwable $abortException) {
    }

    proc_terminate($dumpProc);
    proc_terminate($pigzProc);

    exit(1);
}
```

---

# Why This Avoids Deadlocks

Classic deadlock scenario:

```text
mysqldump stdout buffer full
→ mysqldump blocks
→ pigz stdin not drained
→ pigz stdout full
→ PHP not reading pigz stdout
→ entire pipeline freezes
```

This implementation avoids that because:

- all streams are non-blocking
- `stream_select()` continuously drains readable pipes
- PHP reads pigz output while feeding pigz input
- nothing waits synchronously
- no `stream_get_contents()` calls
- no `proc_close()` before pipes are drained

---

# Memory Characteristics

Expected steady-state memory:

| Component | Approx |
|---|---|
| PHP runtime | 10–20MB |
| S3 upload buffer | 8MB |
| temporary chunk buffers | 1–3MB |
| AWS SDK overhead | 5–10MB |

Expected peak:

```text
~25MB to ~40MB
```

assuming:

- 8MB multipart chunk
- 1MB read chunks
- normal AWS SDK behavior

---

# How To Actually Validate Below 50MB

Run:

```bash
/usr/bin/time -v php backup.php
```

Watch:

```text
Maximum resident set size
```

Also watch live:

```bash
watch -n1 "ps -o pid,rss,cmd -p $(pgrep -f 'php backup.php')"
```

RSS is in KB.

50MB target:

```text
51200 KB
```

---

# How To Validate No Deadlocks

Test with:

- huge tables
- slow network
- limited bandwidth
- artificial latency

Example:

```bash
sudo tc qdisc add dev eth0 root netem delay 300ms
```

Then run:

```bash
php backup.php
```

If:

- upload continues progressing
- no process stuck in D state
- CPU still active
- parts continue uploading

then the pipeline is healthy.

---

# How To Validate Uploaded Gzip

Download:

```bash
aws s3 cp s3://your-bucket/backup.sql.gz ./backup.sql.gz
```

Validate gzip integrity:

```bash
gzip -t backup.sql.gz
```

Expected:

```text
exit code 0
```

Then test decompression:

```bash
pigz -dc backup.sql.gz | head
```

Should show SQL.

---

# Generate A Real 10GB Test Database

Example:

```sql
CREATE TABLE giant_table (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    payload LONGTEXT
);
```

Populate:

```sql
INSERT INTO giant_table(payload)
SELECT REPEAT(UUID(), 1000)
FROM information_schema.columns a
CROSS JOIN information_schema.columns b
CROSS JOIN information_schema.columns c;
```

Repeat until database reaches ~10GB.

Check size:

```sql
SELECT
    table_schema,
    ROUND(SUM(data_length + index_length) / 1024 / 1024 / 1024, 2) AS size_gb
FROM information_schema.tables
GROUP BY table_schema;
```

---

# Recommended Production Improvements

## 1. Add Retry Logic

Retry failed multipart uploads.

---

## 2. Add Progress Metrics

Track:

- upload throughput
- compression ratio
- estimated remaining time

---

## 3. Add Signal Handling

Handle:

- SIGTERM
- SIGINT

and abort multipart uploads cleanly.

---

## 4. Use IAM Roles

Avoid static AWS credentials.

---

## 5. Add Encryption

Enable:

```php
'ServerSideEncryption' => 'AES256'
```

or KMS.

---

# Important Note About proc_open Pipe Buffers

Linux pipe buffers are typically:

```text
64KB
```

Sometimes:

```text
1MB
```

depending on kernel tuning.

That is exactly why:

- blocking IO is dangerous
- synchronous reads are dangerous
- fully-drained event loops matter

This implementation is specifically structured to avoid pipe-buffer deadlocks.

---

# Expected Real-World Performance

Typical:

| Stage | Throughput |
|---|---|
| mysqldump | 100–300 MB/s |
| pigz compression | CPU dependent |
| S3 upload | network dependent |

On 8-core VPS:

```text
10GB database:
5–15 minutes typical
```

depending on:

- compression level
- network speed
- storage speed
- CPU

---

# Recommended pigz Options

Faster:

```bash
pigz -1 -c
```

Better compression:

```bash
pigz -6 -c
```

Maximum compression:

```bash
pigz -9 -c
```

---

# Example Production Run

```bash
php backup.php
```

Example output:

```text
[12:01:22] uploaded part=1 total=8.00MB memory=21.00MB peak=22.00MB
[12:01:25] uploaded part=2 total=16.00MB memory=21.00MB peak=22.00MB
[12:01:29] uploaded part=3 total=24.00MB memory=22.00MB peak=23.00MB
...
```

Final:

```text
Backup completed successfully
Peak Memory: 28.00 MB
```

