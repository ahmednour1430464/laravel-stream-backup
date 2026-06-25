<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Support\BackupPathBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class BackupPathBuilderTest extends TestCase
{
    public function test_builds_tenant_scoped_path(): void
    {
        $builder = new BackupPathBuilder;
        $context = new BackupContext(
            tenantId: 42,
            databaseName: 'acme_main',
            connectionName: 'mysql',
            disk: 's3',
        );
        $at = CarbonImmutable::parse('2026-05-10 02:03:04', 'UTC');

        $path = $builder->build($context, $at);

        self::assertSame('42/acme_main/2026/05/10/acme_main-20260510T020304Z.sql.gz', $path);
    }

    public function test_null_tenant_uses_global_segment(): void
    {
        $builder = new BackupPathBuilder;
        $context = new BackupContext(
            tenantId: null,
            databaseName: 'single_app',
            connectionName: 'mysql',
            disk: 's3',
        );
        $at = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');

        $path = $builder->build($context, $at);

        self::assertSame('_global/single_app/2026/01/01/single_app-20260101T000000Z.sql.gz', $path);
    }

    public function test_empty_string_tenant_uses_global_segment(): void
    {
        $builder = new BackupPathBuilder;
        $context = new BackupContext(
            tenantId: '',
            databaseName: 'db',
            connectionName: 'mysql',
            disk: 's3',
        );
        $at = CarbonImmutable::parse('2026-12-31 23:59:59', 'UTC');

        $path = $builder->build($context, $at);

        self::assertSame('_global/db/2026/12/31/db-20261231T235959Z.sql.gz', $path);
    }

    public function test_string_tenant_is_preserved(): void
    {
        $builder = new BackupPathBuilder;
        $context = new BackupContext(
            tenantId: 'tenant-abc',
            databaseName: 'shop',
            connectionName: 'mysql',
            disk: 's3',
        );
        $at = CarbonImmutable::parse('2026-07-04 12:30:45', 'UTC');

        $path = $builder->build($context, $at);

        self::assertSame('tenant-abc/shop/2026/07/04/shop-20260704T123045Z.sql.gz', $path);
    }
}
