<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Enums\RetentionTier;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Classifies a completed backup into a retention tier so the cleanup job
 * can apply per-tier keep limits.
 *
 *   Monthly -> last Sunday of a calendar month
 *   Weekly  -> any other Sunday
 *   Daily   -> everything else
 */
final class RetentionClassifier
{
    public function classify(DateTimeInterface $at): RetentionTier
    {
        $carbon = $at instanceof CarbonInterface ? $at : \Carbon\CarbonImmutable::instance($at);

        if ($carbon->dayOfWeek === CarbonInterface::SUNDAY) {
            $endOfMonth = $carbon->copy()->endOfMonth();
            // Carbon's previous(SUNDAY) is strictly-before, so when the month
            // already ends on a Sunday we must treat that day itself as the
            // last Sunday of the month.
            $lastSundayOfMonth = $endOfMonth->dayOfWeek === CarbonInterface::SUNDAY
                ? $endOfMonth
                : $endOfMonth->previous(CarbonInterface::SUNDAY);

            if ($carbon->isSameDay($lastSundayOfMonth)) {
                return RetentionTier::Monthly;
            }
            return RetentionTier::Weekly;
        }

        return RetentionTier::Daily;
    }
}
