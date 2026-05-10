<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Models\Backup;
use Aws\S3\S3ClientInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled safety net that aborts S3 multipart uploads left over after a
 * worker crash. Runs hourly by default. S3 charges for in-progress
 * multipart uploads indefinitely, so letting these accumulate is a real
 * storage-cost bug.
 */
class AbortStaleMultipartUploads implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $staleHours;

    public function __construct(?int $staleHours = null)
    {
        $this->staleHours = $staleHours ?? 6;
    }

    public function handle(S3ClientInterface $s3): void
    {
        $threshold = now()->subHours($this->staleHours);

        Backup::query()
            ->where('status', BackupStatus::Uploading->value)
            ->whereNotNull('upload_id')
            ->where('started_at', '<', $threshold)
            ->chunkById(50, function ($backups) use ($s3): void {
                foreach ($backups as $backup) {
                    $this->abort($s3, $backup);
                }
            });
    }

    private function abort(S3ClientInterface $s3, Backup $backup): void
    {
        try {
            $bucket = config("filesystems.disks.{$backup->disk}.bucket", $backup->disk);
            $s3->abortMultipartUpload([
                'Bucket'   => $bucket,
                'Key'      => $backup->path,
                'UploadId' => $backup->upload_id,
            ]);

            $backup->forceFill([
                'status'        => BackupStatus::Aborted->value,
                'finished_at'   => now(),
                'error_message' => 'Aborted by stale multipart cleanup.',
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('stream-backup stale multipart abort failed', [
                'backup_id' => $backup->id,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
