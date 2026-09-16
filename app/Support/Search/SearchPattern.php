<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Support\Persistence\UniqueConstraint;

/**
 * Builds SQL `LIKE` patterns for free-text filters, escaping the wildcards a user may have typed.
 *
 * Patterns are upper-cased and must be matched against an upper-cased column — `UPPER(name)`, or a
 * column already stored folded such as `users.normalized_email`. How much case `LIKE` ignores on
 * its own is the database's decision, so folding both sides is what makes the documented
 * case-insensitive search mean the same thing wherever this runs, including on a MySQL server
 * somebody has configured with a case-sensitive collation.
 *
 * This class and {@see UniqueConstraint} are the only two places that
 * know which database this is. Changing provider means changing both, and nothing else.
 */
final class SearchPattern
{
    /** The escape character that must be passed to `LIKE ... ESCAPE` alongside these patterns. */
    public const string ESCAPE_CHARACTER = '\\';

    /** A pattern matching any value that contains $value, ignoring case. */
    public static function contains(string $value): string
    {
        return '%'.self::escape(mb_strtoupper($value)).'%';
    }

    /**
     * Neutralizes the wildcards a user may have typed, so a search for `%` looks for a percent
     * sign rather than for everything. The escape character goes first, or the escapes added after
     * it would be escaped too.
     */
    private static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
