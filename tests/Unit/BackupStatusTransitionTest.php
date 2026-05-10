<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Enums\BackupStatus;
use PHPUnit\Framework\TestCase;

final class BackupStatusTransitionTest extends TestCase
{
    public function test_pending_can_transition_to_dumping(): void
    {
        self::assertTrue(BackupStatus::Pending->canTransitionTo(BackupStatus::Dumping));
    }

    public function test_pending_can_transition_to_failed(): void
    {
        self::assertTrue(BackupStatus::Pending->canTransitionTo(BackupStatus::Failed));
        self::assertTrue(BackupStatus::Pending->canTransitionTo(BackupStatus::Aborted));
    }

    public function test_pending_cannot_skip_to_completed(): void
    {
        self::assertFalse(BackupStatus::Pending->canTransitionTo(BackupStatus::Completed));
        self::assertFalse(BackupStatus::Pending->canTransitionTo(BackupStatus::Uploading));
        self::assertFalse(BackupStatus::Pending->canTransitionTo(BackupStatus::Verifying));
    }

    public function test_dumping_can_move_forward(): void
    {
        self::assertTrue(BackupStatus::Dumping->canTransitionTo(BackupStatus::Compressing));
        self::assertTrue(BackupStatus::Dumping->canTransitionTo(BackupStatus::Uploading));
        self::assertTrue(BackupStatus::Dumping->canTransitionTo(BackupStatus::Failed));
    }

    public function test_uploading_can_reach_terminal_states(): void
    {
        self::assertTrue(BackupStatus::Uploading->canTransitionTo(BackupStatus::Verifying));
        self::assertTrue(BackupStatus::Uploading->canTransitionTo(BackupStatus::Completed));
        self::assertTrue(BackupStatus::Uploading->canTransitionTo(BackupStatus::TimedOut));
    }

    public function test_verifying_only_reaches_completed_or_failed(): void
    {
        self::assertTrue(BackupStatus::Verifying->canTransitionTo(BackupStatus::Completed));
        self::assertTrue(BackupStatus::Verifying->canTransitionTo(BackupStatus::Failed));
        self::assertFalse(BackupStatus::Verifying->canTransitionTo(BackupStatus::Aborted));
        self::assertFalse(BackupStatus::Verifying->canTransitionTo(BackupStatus::Uploading));
    }

    public function test_terminal_states_cannot_transition(): void
    {
        foreach ([BackupStatus::Completed, BackupStatus::Failed, BackupStatus::Aborted, BackupStatus::TimedOut] as $terminal) {
            self::assertTrue($terminal->isTerminal(), "{$terminal->value} should be terminal");
            self::assertFalse($terminal->canTransitionTo(BackupStatus::Pending));
            self::assertFalse($terminal->canTransitionTo(BackupStatus::Dumping));
            self::assertFalse($terminal->canTransitionTo(BackupStatus::Completed));
        }
    }

    public function test_non_terminal_states_are_not_terminal(): void
    {
        self::assertFalse(BackupStatus::Pending->isTerminal());
        self::assertFalse(BackupStatus::Dumping->isTerminal());
        self::assertFalse(BackupStatus::Compressing->isTerminal());
        self::assertFalse(BackupStatus::Uploading->isTerminal());
        self::assertFalse(BackupStatus::Verifying->isTerminal());
    }
}
