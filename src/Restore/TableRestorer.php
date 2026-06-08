<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
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

        return new RestoreResult(
            tablesRestored:   $tablesRestored,
            totalRowsAffected: $totalRows,
            durationSeconds:  $duration,
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
                    $affected   = $db->unprepared($cleanStatement);
                    $totalRows += (int) $affected;
                }

                $statement = '';
            }
        }

        // Execute any remaining partial statement.
        $remaining = trim($statement);
        if ($remaining !== '' && $remaining !== ';') {
            $affected   = $db->unprepared($remaining);
            $totalRows += (int) $affected;
        }

        return $totalRows;
    }
}
