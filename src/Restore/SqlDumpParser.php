<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Exceptions\TableNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Streaming mysqldump SQL parser.
 *
 * Reads a decompressed SQL dump line-by-line and extracts complete table
 * blocks (DROP, CREATE, INSERT, LOCK/UNLOCK) for the requested tables.
 *
 * Design constraints:
 * - O(1) memory per line — never loads the full dump into memory.
 * - Each extracted table block is written to a php://temp stream that
 *   auto-spills to disk after 2 MB, bounding RAM even for huge tables.
 * - Single pass through the stream: no seeking or re-reading.
 *
 * mysqldump boundary markers detected:
 *   -- Table structure for table `xxx`   ← start of DDL block
 *   -- Dumping data for table `xxx`      ← start of DML block
 *   -- Table structure for table `yyy`   ← end of current table, start of next
 */
final class SqlDumpParser
{
    /**
     * Regex matching the mysqldump "Table structure" comment.
     * Captures the table name (with or without backtick quoting).
     */
    private const TABLE_STRUCTURE_PATTERN = '/^-- Table structure for table `?([^`]+)`?\s*$/';

    /**
     * Regex matching the mysqldump "Dumping data" comment.
     */
    private const TABLE_DATA_PATTERN = '/^-- Dumping data for table `?([^`]+)`?\s*$/';

    /**
     * Max memory (bytes) for php://temp buffers before spilling to disk.
     * 2 MB keeps RAM bounded while avoiding unnecessary disk I/O for
     * small tables.
     */
    private const TEMP_MAX_MEMORY = 2 * 1024 * 1024;

    /**
     * Parse a readable stream and extract SQL blocks for requested tables.
     *
     * @param resource $stream   Decompressed SQL stream (e.g. pigz stdout)
     * @param string[] $tables   Tables to extract (empty = all tables)
     * @return array<string, resource> Map of table_name => php://temp stream
     *                                 containing the full SQL block for that table
     *
     * @throws TableNotFoundException  If any requested table is not found in the dump
     * @throws InvalidBackupException  If the stream is not valid mysqldump output
     */
    public function parse($stream, array $tables): array
    {
        $selectAll       = $tables === [];
        $requestedLookup = array_flip(array_map('trim', $tables));

        /** @var array<string, resource> $buffers Table name => php://temp resource */
        $buffers = [];

        /** @var string|null $currentTable The table we're currently capturing (null = skip) */
        $currentTable = null;

        /** @var bool $foundAnyTable Whether we found at least one table marker */
        $foundAnyTable = false;

        $lineNumber = 0;

        while (($line = fgets($stream)) !== false) {
            $lineNumber++;
            $trimmedLine = rtrim($line, "\r\n");

            // Detect "Table structure for table `xxx`" markers.
            if (preg_match(self::TABLE_STRUCTURE_PATTERN, $trimmedLine, $matches) === 1) {
                $tableName     = $matches[1];
                $foundAnyTable = true;

                if ($selectAll || isset($requestedLookup[$tableName])) {
                    $currentTable = $tableName;

                    if (! isset($buffers[$tableName])) {
                        $buffers[$tableName] = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                        Log::info("[Restore] Found table `{$tableName}` in backup.");
                    }
                } else {
                    $currentTable = null;
                }

                // Write the marker line itself to the buffer if capturing.
                if ($currentTable !== null && isset($buffers[$currentTable])) {
                    fwrite($buffers[$currentTable], $line);
                }

                continue;
            }

            // Detect "Dumping data for table `xxx`" markers.
            if (preg_match(self::TABLE_DATA_PATTERN, $trimmedLine, $matches) === 1) {
                $tableName = $matches[1];

                if ($selectAll || isset($requestedLookup[$tableName])) {
                    $currentTable = $tableName;

                    // Ensure buffer exists (in case data section appears without structure).
                    if (! isset($buffers[$tableName])) {
                        $buffers[$tableName] = fopen('php://temp/maxmemory:' . self::TEMP_MAX_MEMORY, 'r+b');
                    }
                } else {
                    $currentTable = null;
                }
            }

            // Write the current line to the active table's buffer.
            if ($currentTable !== null && isset($buffers[$currentTable])) {
                fwrite($buffers[$currentTable], $line);
            }
        }

        if (! $foundAnyTable) {
            // Close any open buffers.
            foreach ($buffers as $buf) {
                if (is_resource($buf)) {
                    fclose($buf);
                }
            }

            throw new InvalidBackupException(
                'No table markers found in the backup file. '
                . 'The file may be corrupt or not a valid mysqldump output.'
            );
        }

        // Validate that all requested tables were found.
        if (! $selectAll) {
            $found   = array_keys($buffers);
            $missing = array_diff(array_keys($requestedLookup), $found);

            if ($missing !== []) {
                // Close all buffers before throwing.
                foreach ($buffers as $buf) {
                    if (is_resource($buf)) {
                        fclose($buf);
                    }
                }

                throw new TableNotFoundException(sprintf(
                    'The following tables were not found in the backup: %s',
                    implode(', ', array_map(fn (string $t) => "`{$t}`", $missing)),
                ));
            }
        }

        // Rewind all buffers so they're ready for reading.
        foreach ($buffers as $buf) {
            rewind($buf);
        }

        return $buffers;
    }
}
