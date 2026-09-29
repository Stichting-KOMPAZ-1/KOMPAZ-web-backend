<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Writes an ordered list onto the rows under one parent.
 *
 * A row the list names is updated in place, anything else in the list is a new row, and a row the
 * list leaves out is deleted — through the model, never a query delete, so a row holding a file
 * lets go of it (rule 24). A key is looked up among the parent's own rows only: a key from another
 * module's list is a new row here, not a way to rewrite that one.
 *
 * The list's order is the rows' `position`, from nought.
 */
final class OrderedRows
{
    /**
     * @template TRow of Model
     * @template TItem
     *
     * @param  HasMany<TRow, covariant Model>  $relation  the rows under their parent
     * @param  list<TItem>  $items  the list as it should be afterwards
     * @param  Closure(TItem): ?string  $keyOf  the row an item names, if any
     * @param  Closure(TRow, TItem): void  $fill  writes an item's values onto its row
     */
    public static function write(HasMany $relation, array $items, Closure $keyOf, Closure $fill): void
    {
        $existing = $relation->get()->keyBy(fn (Model $row): string => (string) $row->getKey());
        $kept = [];
        $rows = [];

        foreach ($items as $item) {
            $key = $keyOf($item);
            $row = null;

            // A key sent twice is a row once and a new row the second time.
            if ($key !== null && ! isset($kept[$key])) {
                $row = $existing->get($key);

                if ($row !== null) {
                    $kept[$key] = true;
                }
            }

            $rows[] = [$row ?? $relation->make(), $item];
        }

        // Before the writes, so the list never briefly holds a row it is about to lose.
        foreach ($existing as $key => $row) {
            if (! isset($kept[$key])) {
                $row->delete();
            }
        }

        foreach ($rows as $position => [$row, $item]) {
            $fill($row, $item);
            $row->setAttribute('position', $position);
            $row->save();
        }
    }
}
