<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Enums;

enum RetentionTier: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
