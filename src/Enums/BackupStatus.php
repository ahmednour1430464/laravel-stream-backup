<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Enums;

enum BackupStatus: string
{
    case Pending     = 'pending';
    case Dumping     = 'dumping';
    case Compressing = 'compressing';
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
            self::Dumping     => in_array($next, [self::Compressing, self::Uploading, self::Failed, self::Aborted], true),
            self::Compressing => in_array($next, [self::Uploading, self::Failed, self::Aborted], true),
            self::Uploading   => in_array($next, [self::Verifying, self::Completed, self::Failed, self::Aborted, self::TimedOut], true),
            self::Verifying   => in_array($next, [self::Completed, self::Failed], true),
            default           => false,
        };

        self::log($allowed ? 'debug' : 'warning', 'BackupStatus.canTransitionTo evaluated', [
            'from'    => $this->value,
            'to'      => $next->value,
            'allowed' => $allowed,
        ]);

        return $allowed;
    }

    public function isTerminal(): bool
    {
        $terminal = match ($this) {
            self::Completed, self::Failed, self::Aborted, self::TimedOut => true,
            default => false,
        };

        self::log('debug', 'BackupStatus.isTerminal evaluated', [
            'status'   => $this->value,
            'terminal' => $terminal,
        ]);

        return $terminal;
    }

    /**
     * Safely emit a log entry without requiring a fully booted Laravel app.
     *
     * Falls back silently when no logger is bound (e.g. isolated unit tests
     * that do not boot the Testbench container).
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        if (! function_exists('app')) {
            return;
        }

        try {
            $app = app();
            if (! $app->bound('log')) {
                return;
            }
            $app->make('log')->{$level}($message, $context);
        } catch (\Throwable) {
            // Never let logging crash status evaluation.
        }
    }
}
