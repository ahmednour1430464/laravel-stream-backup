<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Strips `DEFINER=...` clauses from mysqldump DDL statements.
 *
 * mysqldump embeds a `DEFINER=\`user\`@\`host\`` clause in every view,
 * procedure, function, trigger and event it exports. Restoring such a dump
 * under a MySQL user that lacks the SUPER or SET_USER_ID privilege (common on
 * managed cloud MySQL — RDS, DigitalOcean Spaces DB, shared hosting) fails
 * with error 1227. Removing the clause makes the object re-create with the
 * restore user as its definer, which is always permitted.
 *
 * This class is pure and stateless: it transforms a single SQL statement
 * string with no side effects, making it trivial to unit-test.
 */
final class DefinerStripper
{
    /**
     * Matches `DEFINER = <value>` where <value> contains no whitespace and no
     * semicolon. This covers every legal MySQL definer spelling:
     *   - `DEFINER=`ahmed`@`127.0.0.1``
     *   - `DEFINER=root@localhost`
     *   - `DEFINER=CURRENT_USER`
     *   - `DEFINER=`CURRENT_USER``
     */
    private const DEFINER_PATTERN = '/DEFINER\s*=\s*[^\s;]+/i';

    /**
     * Only DDL that actually carries a DEFINER clause is eligible. Requiring
     * an object-type keyword (in addition to CREATE/ALTER) avoids corrupting
     * INSERT data or CREATE TABLE defaults that coincidentally contain the
     * literal "DEFINER=".
     */
    private const DDL_KEYWORD_PATTERN = '/\b(CREATE|ALTER)\b/i';

    private const OBJECT_TYPE_PATTERN = '/\b(VIEW|PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i';

    /**
     * Remove any `DEFINER=...` clause from a DDL statement.
     *
     * Non-DDL statements and DDL without a DEFINER clause are returned
     * unchanged.
     */
    public function stripDefiner(string $statement): string
    {
        // Fast path: nothing to do when there is no DEFINER assignment.
        if (preg_match(self::DEFINER_PATTERN, $statement) !== 1) {
            return $statement;
        }

        // Guard: only touch DDL that carries a DEFINER clause.
        if (preg_match(self::DDL_KEYWORD_PATTERN, $statement) !== 1
            || preg_match(self::OBJECT_TYPE_PATTERN, $statement) !== 1) {
            return $statement;
        }

        $stripped = preg_replace(self::DEFINER_PATTERN, '', $statement);

        // preg_replace returns null only on regex engine failure (PCRE limits).
        return $stripped ?? $statement;
    }
}
