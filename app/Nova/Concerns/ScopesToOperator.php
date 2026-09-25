<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Models\User;
use App\Support\Access\OrganizationAccess;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The tenant boundary, for the panel.
 *
 * The panel was platform administrators only until organization administrators were let in, so
 * every listing here was written when there was nobody to hide anything from. Each one now has to
 * ask the same question the API asks on every request: whose data is this? Nova resolves nothing
 * for us — an unscoped `indexQuery` or `detailQuery` is a tenant leak, not an oversight, and a
 * detail page reached by typing a URL is exactly how one would be found.
 *
 * A platform administrator sees everything, as they do through the API. Anybody else sees their
 * own organization and nothing else.
 */
trait ScopesToOperator
{
    /** Whether the operator behind this request may see past their own organization. */
    private static function operatorSeesEveryTenant(): bool
    {
        $operator = Auth::user();

        return $operator instanceof User && OrganizationAccess::isPlatformAdministrator($operator);
    }

    /**
     * Limits a query over rows that carry an `organization_id` to the operator's own tenant.
     *
     * Typed against Nova's own contract rather than the Eloquent builder, and so without generics:
     * that is the type Nova hands to `indexQuery` and `detailQuery`, and the contract is not
     * generic.
     */
    private static function scopeToOperatorsOrganization(Builder $query): Builder
    {
        $operator = Auth::user();

        // No operator means no rows, rather than every row. The panel is unreachable without a
        // session, so this is not a state Nova can be in — but the failure it would otherwise
        // produce is the one worth refusing outright.
        if (! $operator instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (OrganizationAccess::isPlatformAdministrator($operator)) {
            return $query;
        }

        return $query->where('organization_id', $operator->organization_id);
    }
}
