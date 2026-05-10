<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Jobs;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use Ahmednour\StreamBackup\Enums\RetentionTier;
use Ahmednour\StreamBackup\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes completed backups that exceed the per-tier keep limit.
 */
class BackupCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(Config $config): void
    {
        foreach (RetentionTier::cases() as $tier) {
            $limit = (int) $config->get("stream-backup.retention.{$tier->value}", 0);
            if ($limit <= 0) {
                continue;
            }

            $tenantIds = Backup::query()
                ->where('status', BackupStatus::Completed->value)
                ->where('retention_tier', $tier->value)
                ->select('tenant_id')
                ->distinct()
                ->pluck('tenant_id');

            foreach ($tenantIds as $tenantId) {
                $this->pruneTierForTenant($tier, $tenantId, $limit);
            }
        }
    }

    private function pruneTierForTenant(RetentionTier $tier, ?string $tenantId, int $keep): void
    {
        $query = Backup::query()
            ->where('status', BackupStatus::Completed->value)
            ->where('retention_tier', $tier->value);

        $query = $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);

        $stale = $query->orderByDesc('finished_at')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $backup) {
            try {
                if (is_string($backup->path) && $backup->path !== '') {
                    Storage::disk($backup->disk)->delete($backup->path);
                }
            } catch (\Throwable) {
                // leave the DB row in place so a human can investigate
                continue;
            }
            $backup->delete();
        }
    }
}
