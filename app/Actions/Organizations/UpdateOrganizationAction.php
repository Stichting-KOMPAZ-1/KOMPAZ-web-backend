<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\ConflictException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use App\Support\Organizations\OrganizationMessages;
use App\Support\Persistence\UniqueConstraint;
use Illuminate\Database\QueryException;

/** Renames an organization. */
final readonly class UpdateOrganizationAction
{
    public function execute(User $actor, Organization $organization, string $name): Organization
    {
        OrganizationAccess::ensureCanManage($actor, (string) $organization->getKey());

        $normalized = Organization::normalize($name);

        // Folded, like the check on create and like the unique index both of them answer to.
        $taken = Organization::query()
            ->whereKeyNot($organization->getKey())
            ->where('normalized_name', $normalized)
            ->exists();

        if ($taken) {
            throw new ConflictException(OrganizationMessages::NAME_TAKEN);
        }

        $organization->applyName($name);

        try {
            $organization->save();
        } catch (QueryException $exception) {
            if (UniqueConstraint::wasViolated($exception)) {
                throw new ConflictException(OrganizationMessages::NAME_TAKEN);
            }

            throw $exception;
        }

        return $organization->refresh();
    }
}
