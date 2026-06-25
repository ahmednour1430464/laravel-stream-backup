<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\StatementFilter;
use Ahmednour\StreamBackup\Tests\TestCase;

final class StatementFilterTest extends TestCase
{
    private StatementFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new StatementFilter;
    }

    public function test_skips_lock_tables(): void
    {
        self::assertTrue($this->filter->shouldSkip('LOCK TABLES `users` WRITE;'));
    }

    public function test_skips_unlock_tables(): void
    {
        self::assertTrue($this->filter->shouldSkip('UNLOCK TABLES;'));
    }

    public function test_skips_lock_tables_regardless_of_case_and_padding(): void
    {
        self::assertTrue($this->filter->shouldSkip('   lock tables `users` write;'));
        self::assertTrue($this->filter->shouldSkip("\tUNLOCK TABLES;"));
    }

    /**
     * This is the exact statement that triggered MySQL error 1231
     * ("Variable 'time_zone' can't be set to the value of 'NULL'") on the
     * `zenhr_oauth_tokens` table: the dump footer lands in the last table
     * block while the matching header that defines @OLD_TIME_ZONE is
     * dropped by SqlDumpParser.
     */
    public function test_skips_time_zone_restore_footer(): void
    {
        self::assertTrue(
            $this->filter->shouldSkip('/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;')
        );
    }

    public function test_skips_time_zone_save_header(): void
    {
        // The matching "save" statement also references @OLD_ and is
        // harmless-but-pointless; skipping it keeps the policy consistent.
        self::assertTrue(
            $this->filter->shouldSkip('/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;')
        );
    }

    public function test_skips_every_session_save_restore_pair(): void
    {
        // Every statement here references @OLD_* and must be skipped.
        // (SET NAMES utf8 and SET TIME_ZONE='+00:00' carry no @OLD_
        // reference — they are asserted separately in the "keeps" tests.)
        $mustSkip = [
            '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
            '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;',
            '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;',
            '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;',
            '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
            '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
            '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;',
            '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;',
            '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;',
            '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;',
            '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;',
            '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;',
        ];

        foreach ($mustSkip as $statement) {
            self::assertTrue(
                $this->filter->shouldSkip($statement),
                "Failed to skip statement: {$statement}"
            );
        }
    }

    public function test_keeps_set_names_utf8(): void
    {
        // SET NAMES is genuinely useful — it configures the connection
        // charset so INSERT data is interpreted correctly. It carries no
        // @OLD_ reference and must be executed.
        self::assertFalse($this->filter->shouldSkip('/*!40101 SET NAMES utf8 */;'));
    }

    public function test_keeps_set_time_zone_to_literal(): void
    {
        // A real timezone offset, not an @OLD_ restore — must be executed.
        self::assertFalse($this->filter->shouldSkip("/*!40103 SET TIME_ZONE='+00:00' */;"));
    }

    public function test_keeps_create_table(): void
    {
        $statement = 'CREATE TABLE `users` (`id` int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))';

        self::assertFalse($this->filter->shouldSkip($statement));
    }

    public function test_keeps_insert_statement(): void
    {
        $statement = "INSERT INTO `users` (`id`, `email`) VALUES (1, 'a@b.com');";

        self::assertFalse($this->filter->shouldSkip($statement));
    }

    public function test_keeps_dml_containing_old_literal_in_data(): void
    {
        // The literal "@OLD_" inside VALUES must never be treated as a
        // session save/restore statement — it is plain data.
        self::assertFalse(
            $this->filter->shouldSkip("INSERT INTO logs (msg) VALUES ('@OLD_TOKEN_CLEANUP');")
        );
    }

    public function test_keeps_update_with_old_literal_in_set_clause(): void
    {
        // Starts with UPDATE (not SET / /*!), so the @OLD_ guard does not
        // fire even though the literal appears in the SET clause.
        self::assertFalse(
            $this->filter->shouldSkip("UPDATE logs SET msg = '@OLD_VALUE' WHERE id = 1;")
        );
    }

    public function test_matches_case_insensitively(): void
    {
        // mysqldump emits uppercase, but be resilient to lowercase variants.
        self::assertTrue($this->filter->shouldSkip('/*!40103 set time_zone=@old_time_zone */;'));
    }

    public function test_keeps_plain_set_without_old_reference(): void
    {
        // A plain SET of a real session variable to a literal must run.
        self::assertFalse($this->filter->shouldSkip('SET FOREIGN_KEY_CHECKS = 0;'));
    }

    public function test_skips_plain_set_restoring_old_variable(): void
    {
        // Robustness: mysqldump run with --skip-comments may emit the
        // restore without a versioned-comment wrapper.
        self::assertTrue($this->filter->shouldSkip('SET TIME_ZONE=@OLD_TIME_ZONE;'));
    }
}
