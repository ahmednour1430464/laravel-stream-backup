<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Restore\DefinerStripper;
use Ahmednour\StreamBackup\Tests\TestCase;

final class DefinerStripperTest extends TestCase
{
    private DefinerStripper $stripper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripper = new DefinerStripper();
    }

    public function test_strips_definer_from_mysqldump_view_definition(): void
    {
        // This is the exact shape of the statement that triggered error 1227:
        // three version-gated comment lines accumulated into one statement.
        $statement = implode("\n", [
            '/*!50001 CREATE ALGORITHM=UNDEFINED */',
            '/*!50013 DEFINER=`ahmed`@`127.0.0.1` SQL SECURITY DEFINER */',
            '/*!50001 VIEW `account_doctors_view` AS select 1 */;',
        ]);

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringNotContainsString('DEFINER=', $result);
        $this->assertStringNotContainsString('`ahmed`@`127.0.0.1`', $result);
        $this->assertStringContainsString('CREATE ALGORITHM=UNDEFINED', $result);
        $this->assertStringContainsString('SQL SECURITY DEFINER', $result);
        $this->assertStringContainsString('VIEW `account_doctors_view` AS select 1', $result);
    }

    public function test_strips_backtick_quoted_definer_from_procedure(): void
    {
        $statement = 'CREATE DEFINER=`root`@`localhost` PROCEDURE foo() BEGIN SELECT 1; END';

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringNotContainsString('DEFINER=', $result);
        $this->assertStringNotContainsString('`root`@`localhost`', $result);
        $this->assertStringContainsString('PROCEDURE foo()', $result);
    }

    public function test_strips_bare_definer_from_function(): void
    {
        $statement = 'CREATE DEFINER=root@localhost FUNCTION bar() RETURNS INT BEGIN RETURN 1; END';

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringNotContainsString('DEFINER=', $result);
        $this->assertStringNotContainsString('root@localhost', $result);
        $this->assertStringContainsString('FUNCTION bar()', $result);
    }

    public function test_strips_current_user_definer_from_trigger(): void
    {
        $statement = 'CREATE DEFINER=CURRENT_USER TRIGGER mytr BEFORE INSERT ON t FOR EACH ROW BEGIN END';

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringNotContainsString('DEFINER=', $result);
        $this->assertStringContainsString('TRIGGER mytr', $result);
    }

    public function test_strips_definer_from_alter_event(): void
    {
        $statement = 'ALTER DEFINER=`admin`@`%` EVENT ev ON SCHEDULE EVERY 1 DAY DO BEGIN END';

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringNotContainsString('DEFINER=', $result);
        $this->assertStringContainsString('EVENT ev', $result);
    }

    public function test_does_not_touch_insert_containing_definer_literal(): void
    {
        // DML is never eligible, even if it coincidentally contains DEFINER=.
        $statement = "INSERT INTO t (note) VALUES ('DEFINER=x')";

        $this->assertSame($statement, $this->stripper->stripDefiner($statement));
    }

    public function test_does_not_touch_create_table_without_definer(): void
    {
        $statement = 'CREATE TABLE `foo` (`id` int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))';

        $this->assertSame($statement, $this->stripper->stripDefiner($statement));
    }

    public function test_does_not_touch_sql_security_definer_without_assignment(): void
    {
        // "SQL SECURITY DEFINER" has no "DEFINER=" assignment and must be kept.
        $statement = 'CREATE SQL SECURITY DEFINER VIEW v AS SELECT 1';

        $this->assertSame($statement, $this->stripper->stripDefiner($statement));
    }

    public function test_preserves_statement_terminator_and_body(): void
    {
        $statement = 'CREATE DEFINER=`u`@`h` VIEW v AS SELECT 1;';

        $result = $this->stripper->stripDefiner($statement);

        $this->assertStringEndsWith(';', $result);
        $this->assertStringContainsString('VIEW v AS SELECT 1', $result);
    }
}
