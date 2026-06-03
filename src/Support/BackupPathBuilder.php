<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Carbon\CarbonImmutable;

/**
 * Builds the object key / path used by S3 multipart uploads.
 *
 * Pattern: {tenant_id|_global}/{database}/{Y}/{m}/{d}/{database}-{YYYYMMDDTHHMMSS}Z.{extension}
 *
 * The extension defaults to 'sql.gz' which is correct for all built-in
 * drivers (they all produce plain SQL piped through gzip compression).
 * Custom drivers can pass a different extension if needed.
 */
final class BackupPathBuilder
{
    public function build(
        BackupContext $context,
        ?CarbonImmutable $at = null,
        string $extension = 'sql.gz',
    ): string {
        $at ??= CarbonImmutable::now('UTC');

        $tenantSegment = $context->tenantId === null || $context->tenantId === ''
            ? '_global'
            : (string) $context->tenantId;

        return sprintf(
            '%s/%s/%s/%s/%s/%s-%sZ.%s',
            $tenantSegment,
            $context->databaseName,
            $at->format('Y'),
            $at->format('m'),
            $at->format('d'),
            $context->databaseName,
            $at->format('Ymd\THis'),
            $extension,
        );
    }
}
