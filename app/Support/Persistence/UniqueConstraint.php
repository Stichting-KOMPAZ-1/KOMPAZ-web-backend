<?php

declare(strict_types=1);

namespace App\Support\Persistence;

use Illuminate\Database\QueryException;

/**
 * Recognizes a uniqueness violation coming back from the database.
 *
 * A check followed by an insert can always lose the race between the two, and losing it should
 * answer the caller the same way the check would have: a conflict they can act on, not a 500. This
 * is one of the two places that know which database this is — MySQL reports a duplicate key as
 * error 1062.
 */
final class UniqueConstraint
{
    private const int MYSQL_DUPLICATE_ENTRY = 1062;

    public static function wasViolated(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY;
    }
}
