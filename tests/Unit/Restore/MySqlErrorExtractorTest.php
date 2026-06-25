<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\MySqlErrorExtractor;
use Ahmednour\StreamBackup\Tests\TestCase;
use Illuminate\Database\QueryException;

final class MySqlErrorExtractorTest extends TestCase
{
    public function test_extracts_code_from_query_exception_wrapping_pdo_exception(): void
    {
        // This mirrors the real failure path: Laravel wraps the raw PDO error
        // in a QueryException whose previous is the original PDOException.
        $pdo = new \PDOException('SQLSTATE[42000]: Syntax error or access violation');
        $pdo->errorInfo = ['42000', 1227, 'Access denied; you need (at least one of) the SUPER or SET_USER_ID privilege(s)'];

        $e = new QueryException('mysql', '/*!50001 VIEW `v` AS select 1 */;', [], $pdo);

        $this->assertSame(1227, MySqlErrorExtractor::code($e));
    }

    public function test_extracts_code_from_a_direct_pdo_exception(): void
    {
        $pdo = new \PDOException('You do not have the SUPER privilege');
        $pdo->errorInfo = ['42000', 1419, 'You do not have the SUPER privilege'];

        $this->assertSame(1419, MySqlErrorExtractor::code($pdo));
    }

    public function test_falls_back_to_parsing_the_message(): void
    {
        // No previous, no errorInfo — only the message carries the code.
        $e = new \RuntimeException(
            'SQLSTATE[42000]: Syntax error or access violation: 1227 Access denied; '
            .'you need (at least one of) the SUPER or SET_USER_ID privilege(s) for this operation'
        );

        $this->assertSame(1227, MySqlErrorExtractor::code($e));
    }

    public function test_does_not_mistake_sqlstate_for_the_driver_code(): void
    {
        // errorInfo[1] = 0 should be ignored; the fallback must NOT grab the
        // SQLSTATE "42000" from the message — only the real driver code 1227.
        $pdo = new \PDOException('Syntax error or access violation: 1227 Access denied');
        $pdo->errorInfo = ['42000', 0, ''];

        $e = new QueryException('mysql', 'SELECT 1', [], $pdo);

        $this->assertSame(1227, MySqlErrorExtractor::code($e));
    }

    public function test_returns_null_when_no_code_is_discernible(): void
    {
        $e = new \RuntimeException('Something went wrong with no error code');

        $this->assertNull(MySqlErrorExtractor::code($e));
    }
}
