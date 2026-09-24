<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Which side of the archive line the roster shows.
 *
 * Two states, not three: a list is either everybody who is still here or everybody who is not, so
 * whichever one an operator is looking at needs no column to tell the rows apart. A mixed list
 * would, and that column is exactly what this replaces.
 *
 * Named for the column it reads, `deleted_at`, and labelled for the word the panel puts on the
 * button that sets it — the panel says archiving because it also offers a delete that cannot be
 * undone, while the database, the API and the model go on calling this deleted.
 *
 * Choosing nothing is the same as choosing the active users — Nova only applies a filter that
 * carries a value, and without one the model's own soft-delete scope already leaves the deleted
 * out. There is therefore no state of this filter in which a deleted user appears unannounced.
 */
final class UserDeletionState extends Filter
{
    private const string ACTIVE = 'active';

    private const string DELETED = 'deleted';

    public function name(): string
    {
        return 'Gearchiveerd';
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        if ($value !== self::DELETED) {
            return $query;
        }

        // The scope that hides them has to go before they can be asked for by name.
        return $query
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull('deleted_at');
    }

    /** @return array<string, string> */
    public function options(NovaRequest $request): array
    {
        return [
            'Actieve gebruikers' => self::ACTIVE,
            'Alleen gearchiveerde gebruikers' => self::DELETED,
        ];
    }

    public function default(): string
    {
        return self::ACTIVE;
    }
}
