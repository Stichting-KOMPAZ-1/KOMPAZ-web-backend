<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Events\OrganizationLogoDiscarded;
use App\Exceptions\NotFoundException;
use App\Models\Organization;
use App\Models\OrganizationLogo;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Facades\DB;

/** Removes the organization's logo, which puts it back to the placeholder. */
final readonly class DeleteOrganizationLogoAction
{
    public function execute(User $actor, Organization $organization): void
    {
        OrganizationAccess::ensureCanManage($actor, (string) $organization->getKey());

        // Loaded rather than deleted where it sits, because the row is what knows the key that the
        // event has to carry. It is a few short columns now that the image itself lives elsewhere.
        $logo = $organization->logo
            ?? throw NotFoundException::for(class_basename(OrganizationLogo::class), (string) $organization->getKey());

        DB::transaction(function () use ($logo): void {
            $key = $logo->storage_key;
            $logo->delete();

            OrganizationLogoDiscarded::dispatch($key);
        });
    }
}
