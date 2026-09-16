<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\UserStatus;
use App\Events\OrganizationLogoDiscarded;
use App\Events\UserDeleted;
use App\Exceptions\ConflictException;
use App\Models\Organization;
use App\Models\OrganizationLogo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Deletes an organization along with its users. */
final readonly class DeleteOrganizationAction
{
    public function execute(User $actor, Organization $organization): void
    {
        // Said out loud rather than left to follow from something else. Every platform
        // administrator belongs to this organization, so the check below already refuses today;
        // that is a consequence of where the role is held, and the day it stops being true is not
        // the day to discover that this request would take every administrator, every tenant's
        // owner and the seeder's answer to "is the database empty" with it.
        if ($organization->is_platform) {
            throw new ConflictException('De organisatie die het platform beheert kan niet worden verwijderd.');
        }

        if ($actor->organization_id === $organization->getKey()) {
            throw new ConflictException('Een organisatie kan niet worden verwijderd door een van haar eigen leden.');
        }

        DB::transaction(function () use ($organization): void {
            // Read before the delete, because after it there is nobody left to ask. Only the
            // addresses and names are read: what the notice needs, rather than rows that are about
            // to stop existing.
            //
            // Active users only, and the same two exclusions deleting one user makes. Somebody
            // already deleted was told when it happened; somebody still invited never had the
            // account this notice is about, and telling them it is gone would be the first they had
            // heard of it.
            $members = User::query()
                ->where('organization_id', $organization->getKey())
                ->whereNull('deleted_at')
                ->where('status', UserStatus::Active->value)
                ->get(['id', 'email', 'name']);

            // The logo row goes with the organization on the cascade, which means nothing would be
            // left to raise the removal of its file. Asked for here instead, before the row is
            // gone.
            $logoKey = OrganizationLogo::query()
                ->where('organization_id', $organization->getKey())
                ->value('storage_key');

            $organization->delete();

            // Raised after the row is gone, and dispatched after the commit. From each recipient's
            // side this is exactly the account-deleted notice, because that is what happened to
            // them.
            foreach ($members as $member) {
                UserDeleted::dispatch((string) $member->getKey(), $member->email, $member->name);
            }

            if ($logoKey !== null) {
                OrganizationLogoDiscarded::dispatch((string) $logoKey);
            }
        });
    }
}
