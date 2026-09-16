<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\ConflictException;
use App\Models\Organization;
use App\Support\Organizations\OrganizationMessages;
use App\Support\Persistence\UniqueConstraint;
use Illuminate\Database\QueryException;

/** Creates an organization. */
final readonly class CreateOrganizationAction
{
    public function execute(string $name): Organization
    {
        $organization = new Organization;
        $organization->applyName($name);

        // Compared folded, and matched by the folded unique index behind it, so "Elkerliek" and
        // "elkerliek" are the same name rather than two organizations a person cannot tell apart.
        if (Organization::query()->where('normalized_name', $organization->normalized_name)->exists()) {
            throw new ConflictException(OrganizationMessages::NAME_TAKEN);
        }

        try {
            $organization->save();
        } catch (QueryException $exception) {
            // Losing the race between the check above and this insert ends the same way it would
            // have: a conflict the caller can act on, from the index rather than from the check.
            if (UniqueConstraint::wasViolated($exception)) {
                throw new ConflictException(OrganizationMessages::NAME_TAKEN);
            }

            throw $exception;
        }

        return $organization;
    }
}
