<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Aws\S3\S3Client;

function loadEnv(string $path): void
{
    if (! file_exists($path)) {
        throw new RuntimeException('.env file not found');
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

loadEnv(__DIR__.'/.env');

// ─── Constants ────────────────────────────────────────────────────────────────
const READ_CHUNK_SIZE = 64 * 1024;       //  64 KB  — read chunks from pipes
const S3_PART_SIZE = 32 * 1024 * 1024; //  32 MB  — S3 multipart part size
//
// Why 32 MB instead of 8 MB?
// Fewer parts = fewer HTTP round-trips = faster wall-clock time.
// 8 MB parts on a 320 MB backup = 40 parts x 1 HTTP call each.
// 32 MB parts = 10 parts. AWS allows up to 10,000 parts; 32 MB keeps
// you safely under that limit even for 300 GB databases.

// ─── Config ───────────────────────────────────────────────────────────────────
$config = [
    'mysql' => [
        'host' => $_ENV['DB_HOST'],
        'port' => (int) $_ENV['DB_PORT'],
        'database' => $_ENV['DB_NAME'],
        'username' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
    ],
    's3' => [
        'bucket' => $_ENV['DO_SPACES_BUCKET'],
        'key' => date('Y-m-d_H-i-s').'.sql.gz',
    ],
];

// ─── S3 Client ────────────────────────────────────────────────────────────────
$s3 = new S3Client([
    'version' => 'latest',
    'region' => $_ENV['DO_SPACES_REGION'],
    'endpoint' => $_ENV['DO_SPACES_ENDPOINT'],
    'credentials' => [
        'key' => $_ENV['DO_SPACES_KEY'],
        'secret' => $_ENV['DO_SPACES_SECRET'],
    ],
    'use_path_style_endpoint' => false,
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
]);

// ─── Secure credentials file ──────────────────────────────────────────────────
// Never pass -p on the command line — it leaks in `ps aux`.
$cnfPath = tempnam(sys_get_temp_dir(), 'bkp_cnf_');
file_put_contents($cnfPath, sprintf(
    "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
    $config['mysql']['host'],
    $config['mysql']['port'],
    $config['mysql']['username'],
    $config['mysql']['password'],
));
chmod($cnfPath, 0600);

$dumpCommand = sprintf(
    'mysqldump --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables %s',
    escapeshellarg($cnfPath),
    escapeshellarg($config['mysql']['database'])
);

// ─── Processes ────────────────────────────────────────────────────────────────
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$dumpProc = proc_open($dumpCommand, $descriptors, $dumpPipes);
if (! is_resource($dumpProc)) {
    unlink($cnfPath);
    throw new RuntimeException('Failed to start mysqldump');
}

// Ensure the credentials file is removed on ANY exit path.
// We cannot unlink() here: proc_open only spawns the child, it does not
// wait for mysqldump to open --defaults-extra-file. Deleting too early
// causes: "Failed to open required defaults file".
register_shutdown_function(static function () use ($cnfPath): void {
    if (is_file($cnfPath)) {
        @unlink($cnfPath);
    }
});

$pigzProc = proc_open('pigz -4 -c', $descriptors, $pigzPipes);
if (! is_resource($pigzProc)) {
    proc_terminate($dumpProc);
    throw new RuntimeException('Failed to start pigz');
}

// Non-blocking on all read ends and pigz stdin
stream_set_blocking($dumpPipes[1], false);
stream_set_blocking($dumpPipes[2], false);
stream_set_blocking($pigzPipes[0], false); // pigz stdin — write side
stream_set_blocking($pigzPipes[1], false);
stream_set_blocking($pigzPipes[2], false);

// ─── Multipart upload init ────────────────────────────────────────────────────
$upload = $s3->createMultipartUpload([
    'Bucket' => $config['s3']['bucket'],
    'Key' => $config['s3']['key'],
    'ContentType' => 'application/gzip',
]);
$uploadId = $upload['UploadId'];
$parts = [];
$partNumber = 1;
$totalBytesUploaded = 0;
$startTime = microtime(true);

// ─── Temp stream buffer ───────────────────────────────────────────────────────
// php://temp uses memory up to 2MB, then transparently swaps to a tmp file.
// This completely eliminates string-copy allocations and GC pressure.
// The stream is rewound and passed directly to uploadPart — zero extra copies.
$partBuffer = fopen('php://temp', 'r+b');
$bufferSize = 0;

$dumpDone = false;
$pigzInputDone = false;
$pigzDone = false;

// ─── Helper: flush the temp buffer as one S3 part ─────────────────────────────
$flushPart = function () use (
    $s3, $config, $uploadId, &$parts, &$partNumber,
    &$totalBytesUploaded, &$partBuffer, &$bufferSize
): void {
    rewind($partBuffer);

    $result = $s3->uploadPart([
        'Bucket' => $config['s3']['bucket'],
        'Key' => $config['s3']['key'],
        'UploadId' => $uploadId,
        'PartNumber' => $partNumber,
        'Body' => $partBuffer, // stream resource — no string copy
        'ContentLength' => $bufferSize,
    ]);

    $parts[] = [
        'ETag' => $result['ETag'],
        'PartNumber' => $partNumber,
    ];

    $totalBytesUploaded += $bufferSize;

    echo sprintf(
        "[%s] part=%d  uploaded=%.2f MB  memory=%.2f MB  peak=%.2f MB\n",
        date('H:i:s'),
        $partNumber,
        $totalBytesUploaded / 1024 / 1024,
        memory_get_usage(true) / 1024 / 1024,
        memory_get_peak_usage(true) / 1024 / 1024,
    );

    $partNumber++;

    // Reuse the same stream — truncate and rewind instead of re-opening.
    // ftruncate + rewind is O(1) and does not allocate.
    ftruncate($partBuffer, 0);
    rewind($partBuffer);
    $bufferSize = 0;
};

// ─── Pipeline loop ────────────────────────────────────────────────────────────
try {
    while (true) {
        $read = [];
        $write = null;
        $except = null;

        if (! $dumpDone) {
            $read[] = $dumpPipes[1];
        }

        if (! $pigzDone) {
            $read[] = $pigzPipes[1];
        }

        if (empty($read)) {
            break;
        }

        $changed = stream_select($read, $write, $except, 0, 200_000);

        if ($changed === false) {
            throw new RuntimeException('stream_select failed');
        }

        // ── Read from mysqldump stdout → write to pigz stdin ──────────────────
        if (! $dumpDone && in_array($dumpPipes[1], $read, true)) {
            $data = fread($dumpPipes[1], READ_CHUNK_SIZE);

            if ($data !== '' && $data !== false) {
                // Write loop: fwrite on a non-blocking pipe may write less
                // than requested if the pipe buffer is full.
                $offset = 0;
                $len = strlen($data);

                while ($offset < $len) {
                    $written = @fwrite($pigzPipes[0], substr($data, $offset));

                    if ($written === false || $written === 0) {
                        // pigz stdin buffer full — yield to stream_select
                        // on the next iteration rather than spinning.
                        usleep(1_000);

                        continue;
                    }

                    $offset += $written;
                }
            }

            if (feof($dumpPipes[1])) {
                fclose($dumpPipes[1]);
                fclose($pigzPipes[0]); // Signal EOF to pigz
                $dumpDone = true;
                $pigzInputDone = true;
            }
        }

        // ── Read from pigz stdout → write to temp buffer → upload part ────────
        if (! $pigzDone && in_array($pigzPipes[1], $read, true)) {
            $compressed = fread($pigzPipes[1], READ_CHUNK_SIZE);

            if ($compressed !== '' && $compressed !== false) {
                fwrite($partBuffer, $compressed);
                $bufferSize += strlen($compressed);

                // Flush a part as soon as the buffer hits the part size.
                // The temp stream is passed directly — no string is allocated.
                if ($bufferSize >= S3_PART_SIZE) {
                    ($flushPart)();
                }
            }

            if (feof($pigzPipes[1])) {
                fclose($pigzPipes[1]);
                $pigzDone = true;
            }
        }
    }

    // ── Upload the final (possibly smaller) part ──────────────────────────────
    if ($bufferSize > 0) {
        ($flushPart)();
    }

    if (empty($parts)) {
        $stderr = stream_get_contents($dumpPipes[2]);
        throw new RuntimeException('No data uploaded. mysqldump stderr: '.trim((string) $stderr));
    }

    // ── Verify child processes succeeded BEFORE completing the upload.
    //    Otherwise a mid-stream mysqldump failure would produce a completed
    //    but corrupted gzip on S3 that we can no longer abort.
    $dumpStatus = proc_get_status($dumpProc);
    $pigzStatus = proc_get_status($pigzProc);

    // If a process is still flagged as running, wait briefly for it to exit.
    $waitStart = microtime(true);
    while (($dumpStatus['running'] || $pigzStatus['running']) && (microtime(true) - $waitStart) < 5) {
        usleep(50_000);
        $dumpStatus = proc_get_status($dumpProc);
        $pigzStatus = proc_get_status($pigzProc);
    }

    $dumpExit = $dumpStatus['running'] ? -1 : $dumpStatus['exitcode'];
    $pigzExit = $pigzStatus['running'] ? -1 : $pigzStatus['exitcode'];

    if ($dumpExit !== 0) {
        $stderr = stream_get_contents($dumpPipes[2]);
        throw new RuntimeException(
            "mysqldump exited with code $dumpExit. stderr: ".trim((string) $stderr)
        );
    }
    if ($pigzExit !== 0) {
        $stderr = stream_get_contents($pigzPipes[2]);
        throw new RuntimeException(
            "pigz exited with code $pigzExit. stderr: ".trim((string) $stderr)
        );
    }

    // ── Complete multipart upload ─────────────────────────────────────────────
    $s3->completeMultipartUpload([
        'Bucket' => $config['s3']['bucket'],
        'Key' => $config['s3']['key'],
        'UploadId' => $uploadId,
        'MultipartUpload' => ['Parts' => $parts],
    ]);

    proc_close($dumpProc);
    $dumpProc = null;
    proc_close($pigzProc);
    $pigzProc = null;

    $duration = microtime(true) - $startTime;

    echo PHP_EOL;
    echo 'Backup completed'.PHP_EOL;
    echo 'S3 Key:      '.$config['s3']['key'].PHP_EOL;
    echo 'Uploaded:    '.round($totalBytesUploaded / 1024 / 1024, 2).' MB'.PHP_EOL;
    echo 'Duration:    '.round($duration, 2).' sec'.PHP_EOL;
    echo 'Peak Memory: '.round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB'.PHP_EOL;

} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().PHP_EOL;

    try {
        $s3->abortMultipartUpload([
            'Bucket' => $config['s3']['bucket'],
            'Key' => $config['s3']['key'],
            'UploadId' => $uploadId,
        ]);
        echo 'Multipart upload aborted.'.PHP_EOL;
    } catch (Throwable) {
        // best effort
    }

    if (is_resource($dumpProc)) {
        proc_terminate($dumpProc);
        proc_close($dumpProc);
    }
    if (is_resource($pigzProc)) {
        proc_terminate($pigzProc);
        proc_close($pigzProc);
    }

    exit(1);
}
