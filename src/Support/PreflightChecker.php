<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PreflightChecker
{
    public function check(string $disk): void
    {
        $filesystem = Storage::disk($disk);
        $testPath = '.stream-backup-preflight-' . Str::random(16);

        try {
            $filesystem->put($testPath, 'pre-flight check');
            $filesystem->delete($testPath);
        } catch (\Throwable $e) {
            throw new RuntimeException("Pre-flight check failed for disk '{$disk}'. The bucket/disk may be unreachable, read-only, or lack delete permissions.", 0, $e);
        }
    }
}
