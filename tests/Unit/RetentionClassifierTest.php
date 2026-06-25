<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Enums\RetentionTier;
use Ahmednour\StreamBackup\Support\RetentionClassifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RetentionClassifierTest extends TestCase
{
    public function test_weekday_classifies_as_daily(): void
    {
        $classifier = new RetentionClassifier;

        // 2026-05-13 is a Wednesday.
        $when = CarbonImmutable::parse('2026-05-13 02:00:00', 'UTC');

        self::assertSame(RetentionTier::Daily, $classifier->classify($when));
    }

    public function test_non_last_sunday_classifies_as_weekly(): void
    {
        $classifier = new RetentionClassifier;

        // 2026-05-10 is a Sunday, but NOT the last Sunday of May 2026
        // (the last Sunday of May 2026 is 2026-05-31).
        $when = CarbonImmutable::parse('2026-05-10 02:00:00', 'UTC');

        self::assertSame(RetentionTier::Weekly, $classifier->classify($when));
    }

    public function test_last_sunday_of_month_classifies_as_monthly(): void
    {
        $classifier = new RetentionClassifier;

        // 2026-05-31 is a Sunday and is the last Sunday of May 2026.
        $when = CarbonImmutable::parse('2026-05-31 02:00:00', 'UTC');

        self::assertSame(RetentionTier::Monthly, $classifier->classify($when));
    }

    public function test_classify_accepts_native_datetime(): void
    {
        $classifier = new RetentionClassifier;

        $when = new \DateTimeImmutable('2026-05-13 02:00:00', new \DateTimeZone('UTC'));

        self::assertSame(RetentionTier::Daily, $classifier->classify($when));
    }
}
