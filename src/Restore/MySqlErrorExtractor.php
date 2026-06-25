<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Illuminate\Database\QueryException;

/**
 * Extracts the MySQL driver-specific error code from a thrown exception.
 *
 * Laravel wraps raw PDO errors in {@see QueryException};
 * the original {@see \PDOException} is available via `getPrevious()` and
 * exposes a populated `errorInfo` array whose second element is the
 * driver-specific code (e.g. 1227 for the DEFINER/SUPER access-denied error).
 *
 * This is the value that should be matched against the configurable
 * `stream-backup.restore.skippable_error_codes` list — NOT the SQLSTATE
 * string (which is generic, e.g. "42000").
 *
 * Pure and stateless; trivially unit-testable.
 */
final class MySqlErrorExtractor
{
    /**
     * Fallback pattern that locates the MySQL error code embedded in the
     * exception message, e.g. "... access violation: 1227 Access denied ...".
     *
     * It requires a colon, the numeric code, then trailing whitespace. It will
     * NOT match the SQLSTATE portion of "SQLSTATE[42000]:" because the digits
     * there (42000) are not preceded by a colon.
     */
    private const MESSAGE_CODE_PATTERN = '/:\s*(\d{3,5})\s/';

    /**
     * Resolve the MySQL driver-specific error code from an exception.
     *
     * @return int|null The numeric MySQL error code (e.g. 1227), or null when
     *                  it cannot be reliably determined.
     */
    public static function code(\Throwable $e): ?int
    {
        // The exception itself may be a PDOException (when raised outside
        // Laravel's query runner), or it may wrap one as its previous
        // (the normal QueryException-wrapped case).
        $pdo = $e instanceof \PDOException ? $e : $e->getPrevious();

        // Preferred path: PDO errorInfo[1] is the driver-specific code.
        if ($pdo instanceof \PDOException && isset($pdo->errorInfo[1])) {
            $code = (int) $pdo->errorInfo[1];
            if ($code > 0) {
                return $code;
            }
        }

        // Fallback: parse the code out of the message text.
        if (preg_match(self::MESSAGE_CODE_PATTERN, $e->getMessage(), $matches) === 1) {
            $code = (int) $matches[1];
            if ($code > 0) {
                return $code;
            }
        }

        return null;
    }
}
