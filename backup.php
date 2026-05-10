<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException(".env file not found");
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

loadEnv(__DIR__ . '/.env');

const READ_CHUNK_SIZE = 1024 * 1024; // 1MB
const S3_PART_SIZE = 8 * 1024 * 1024; // 8MB

$config = [
    'mysql' => [
        'host' => $_ENV['DB_HOST'],
        'port' => $_ENV['DB_PORT'],
        'database' => $_ENV['DB_NAME'],
        'username' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
    ],
    's3' => [
        'bucket' => $_ENV['DO_SPACES_BUCKET'],
        'key' => date('Y-m-d_H-i-s') . '.sql.gz',
    ],
];

$s3 = new S3Client([
    'version' => 'latest',
    'region'  => $_ENV['DO_SPACES_REGION'],

    'endpoint' => $_ENV['DO_SPACES_ENDPOINT'],

    'credentials' => [
        'key'    => $_ENV['DO_SPACES_KEY'],
        'secret' => $_ENV['DO_SPACES_SECRET'],
    ],

    'use_path_style_endpoint' => false,

    // DigitalOcean Spaces does not support the default AWS SDK checksum
    // behavior introduced in aws-sdk-php >= 3.337. Without these options,
    // CompleteMultipartUpload fails with: MalformedXML.
    'request_checksum_calculation'  => 'when_required',
    'response_checksum_validation'  => 'when_required',
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

    if (empty($parts)) {
        throw new RuntimeException(
            'No data was uploaded. mysqldump produced no output. '
            . 'stderr: ' . trim((string) stream_get_contents($dumpPipes[2]))
        );
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

    if ($dumpExit !== 0) {
        throw new RuntimeException("mysqldump exited with code $dumpExit");
    }

    if ($pigzExit !== 0) {
        throw new RuntimeException("pigz exited with code $pigzExit");
    }

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
