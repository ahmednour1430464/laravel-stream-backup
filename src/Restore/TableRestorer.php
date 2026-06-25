<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Executes parsed table SQL blocks against a database connection inside
 * a single transaction.
 *
 * Execution flow:
 *   1. Disable FK checks (SET FOREIGN_KEY_CHECKS = 0)
 *   2. Begin transaction
 *   3. For each table: read SQL from php://temp, execute statements
 *   4. Commit transaction
 *   5. Re-enable FK checks (in finally block)
 *
 * On failure: rollback, re-enable FK checks, throw RestoreFailedException.
 */
final class TableRestorer
{
    private readonly DefinerStripper $definerStripper;
    private readonly bool $stripDefiners;
    private readonly bool $skipOnError;

    /** @var array<int, int> */
    private readonly array $skippableCodes;

    private int $skippedCount = 0;

    public function __construct(Config $config)
    {
        $this->definerStripper = new DefinerStripper();
        $this->stripDefiners   = (bool) $config->get('stream-backup.restore.strip_definers', true);
        $this->skipOnError     = (bool) $config->get('stream-backup.restore.skip_on_error', true);
        $this->skippableCodes  = array_map('intval', (array) $config->get('stream-backup.restore.skippable_error_codes', [1227]));
    }

    /**
     * Restore the given table blocks into the target database.
     *
     * @param array<string, resource> $tableBlocks Map of table_name => php://temp stream
     * @param string                  $connection  Laravel DB connection name
     * @return RestoreResult
     *
     * @throws RestoreFailedException If any SQL execution fails
     */
    public function restore(array $tableBlocks, string $connection, float $startTime): RestoreResult
    {
        $db = DB::connection($connection);
        $totalRows      = 0;
        $tablesRestored = [];
        $currentTable   = null;
        $this->skippedCount = 0;

        try {
            $db->unprepared('SET FOREIGN_KEY_CHECKS = 0');
            Log::info('[Restore] Disabled foreign key checks.');

            $db->beginTransaction();

            foreach ($tableBlocks as $tableName => $buffer) {
                $currentTable = $tableName;
                Log::info("[Restore] Restoring table `{$tableName}`...");

                $rowsForTable = $this->executeBuffer($db, $buffer, $tableName);
                $totalRows += $rowsForTable;
                $tablesRestored[] = $tableName;

                Log::info("[Restore] Table `{$tableName}` restored ({$rowsForTable} rows affected).");
            }

            $isImplicitlyCommitted = method_exists($db, 'getPdo')
                && $db->getPdo() !== null
                && method_exists($db->getPdo(), 'inTransaction')
                && $db->transactionLevel() > 0
                && ! $db->getPdo()->inTransaction();

            if ($isImplicitlyCommitted) {
                Log::info('[Restore] Implicit commit detected (likely DDL). Resetting transaction state.');
                $db->rollBack();
            } else {
                $db->commit();
                Log::info('[Restore] Transaction committed successfully.');
            }
        } catch (\Throwable $e) {
            try {
                $db->rollBack();
            } catch (\Throwable) {
                // Best effort — may fail if connection is already broken.
            }

            $message = $currentTable !== null
                ? "Restore failed on table `{$currentTable}`: {$e->getMessage()}"
                : "Restore failed: {$e->getMessage()}";

            throw new RestoreFailedException($message, 0, $e);
        } finally {
            try {
                $db->unprepared('SET FOREIGN_KEY_CHECKS = 1');
                Log::info('[Restore] Re-enabled foreign key checks.');
            } catch (\Throwable) {
                Log::warning('[Restore] Failed to re-enable foreign key checks.');
            }

            // Close all php://temp buffers.
            foreach ($tableBlocks as $buffer) {
                if (is_resource($buffer)) {
                    @fclose($buffer);
                }
            }
        }

        $duration = microtime(true) - $startTime;

        if ($this->skippedCount > 0) {
            Log::warning("[Restore] Completed with {$this->skippedCount} skipped statement(s) due to skippable errors.");
        }

        return new RestoreResult(
            tablesRestored:    $tablesRestored,
            totalRowsAffected: $totalRows,
            durationSeconds:   $duration,
            skippedStatements: $this->skippedCount,
        );
    }

    /**
     * Execute SQL statements from a php://temp buffer against the connection.
     *
     * Reads the buffer line-by-line, accumulating multi-line statements
     * (delimited by `;`), and executes each complete statement.
     *
     * @param ConnectionInterface $db
     * @param resource           $buffer
     * @param string             $tableName
     * @return int Rows affected
     */
    private function executeBuffer(ConnectionInterface $db, $buffer, string $tableName): int
    {
        $totalRows = 0;
        $statement = '';

        while (($line = fgets($buffer)) !== false) {
            $trimmed = trim($line);

            // Skip empty lines and SQL comments (-- and /* ... */).
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $statement .= $line;

            // Execute when we reach a semicolon at the end of a line.
            // mysqldump always terminates statements with ";\n".
            if (str_ends_with($trimmed, ';')) {
                $cleanStatement = trim($statement);

                if ($cleanStatement !== '' && $cleanStatement !== ';') {
                    $totalRows += $this->executeStatement($db, $cleanStatement, $tableName);
                }

                $statement = '';
            }
        }

        // Execute any remaining partial statement.
        $remaining = trim($statement);
        if ($remaining !== '' && $remaining !== ';') {
            $totalRows += $this->executeStatement($db, $remaining, $tableName);
        }

        return $totalRows;
    }

    /**
     * Execute a single SQL statement, applying DEFINER stripping and the
     * configurable skip-on-error safety net.
     *
     * @param ConnectionInterface $db
     * @param string              $statement
     * @param string              $tableName
     * @return int Rows affected
     */
    private function executeStatement(ConnectionInterface $db, string $statement, string $tableName): int
    {
        if ($this->stripDefiners) {
            $statement = $this->definerStripper->stripDefiner($statement);
        }

        try {
            return (int) $db->unprepared($statement);
        } catch (\Throwable $e) {
            $code = MySqlErrorExtractor::code($e);

            if ($this->skipOnError && $code !== null && in_array($code, $this->skippableCodes, true)) {
                Log::warning(
                    "[Restore] Skipped statement in `{$tableName}` due to skippable MySQL error (code {$code}): {$e->getMessage()}",
                    ['statement' => mb_substr($statement, 0, 500)]
                );

                $this->skippedCount++;

                return 0;
            }

            throw $e;
        }
    }
}
