<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Exceptions\ConflictException;
use App\Models\Organization;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Support\Facades\DB;

/**
 * Puts an archived organization back into service.
 *
 * Restores access and nothing else. The tokens and sign-in links archiving deleted stay deleted:
 * they were credentials issued before the organization closed, and handing one back would undo
 * more than the archiving did. Members sign in again from the login page, which is what restoring
 * a deleted user asks of them too.
 *
 * Takes no actor, unlike archiving. Archiving refuses the caller's own organization because it
 * would take their access with it; there is no such rule in the other direction, and a parameter
 * that exists only for symmetry would read as a check that is not there.
 */
final readonly class UnarchiveOrganizationAction
{
    public function execute(Organization $organization): Organization
    {
        return DB::transaction(function () use ($organization): Organization {
            $restored = Organization::query()
                ->whereKey($organization->getKey())
                ->whereNotNull('archived_at')
                ->update(['archived_at' => null]);

            if ($restored === 0) {
                throw new ConflictException(OrganizationMessages::NOT_ARCHIVED);
            }

            // Through the model as well, so reopening has a recorded author for the same reason
            // closing does.
            $organization->archived_at = null;
            $organization->save();

            return $organization;
        });
    }
}
