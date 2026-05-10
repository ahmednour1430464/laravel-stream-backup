<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Carbon\CarbonImmutable;

/**
 * Builds the object key / path used by S3 multipart uploads.
 *
 * Pattern: {tenant_id|_global}/{database}/{Y}/{m}/{d}/{database}-{YYYYMMDDTHHMMSS}Z.sql.gz
 */
final class BackupPathBuilder
{
    public function build(BackupContext $context, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now('UTC');

        $tenantSegment = $context->tenantId === null || $context->tenantId === ''
            ? '_global'
            : (string) $context->tenantId;

        return sprintf(
            '%s/%s/%s/%s/%s/%s-%sZ.sql.gz',
            $tenantSegment,
            $context->databaseName,
            $at->format('Y'),
            $at->format('m'),
            $at->format('d'),
            $context->databaseName,
            $at->format('Ymd\THis'),
        );
    }
}
