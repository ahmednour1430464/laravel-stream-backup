<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Enums;

enum BackupStatus: string
{
    case Pending     = 'pending';
    case Dumping     = 'dumping';
    case Compressing = 'compressing';
    case Encrypting  = 'encrypting';
    case Uploading   = 'uploading';
    case Verifying   = 'verifying';
    case Completed   = 'completed';
    case Failed      = 'failed';
    case Aborted     = 'aborted';
    case TimedOut    = 'timed_out';

    public function canTransitionTo(self $next): bool
    {
        $allowed = match ($this) {
            self::Pending     => $next === self::Dumping || $next === self::Failed || $next === self::Aborted,
            self::Dumping     => in_array($next, [self::Compressing, self::Encrypting, self::Uploading, self::Failed, self::Aborted], true),
            self::Compressing => in_array($next, [self::Encrypting, self::Uploading, self::Failed, self::Aborted], true),
            self::Encrypting  => in_array($next, [self::Uploading, self::Failed, self::Aborted], true),
            self::Uploading   => in_array($next, [self::Verifying, self::Completed, self::Failed, self::Aborted, self::TimedOut], true),
            self::Verifying   => in_array($next, [self::Completed, self::Failed], true),
            default           => false,
        };

        return $allowed;
    }

    public function isTerminal(): bool
    {
        $terminal = match ($this) {
            self::Completed, self::Failed, self::Aborted, self::TimedOut => true,
            default => false,
        };

        return $terminal;
    }
}
