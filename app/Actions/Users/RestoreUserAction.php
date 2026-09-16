<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Access\OrganizationAccess;

/**
 * Restores a deleted user, leaving one who is not deleted as they are.
 *
 * Their sign-in links and sessions are not restored with them, so they start again from the login
 * page. This and inviting are the two places that deliberately look past the soft-delete filter;
 * the route binding for this one therefore resolves a trashed row.
 */
final readonly class RestoreUserAction
{
    public function execute(User $actor, User $user): User
    {
        OrganizationAccess::ensureCanManage($actor, $user->organization_id);
        OrganizationAccess::ensureCanManageRole($actor, $user->role);

        if ($user->isDeleted()) {
            $user->restore();
        }

        return $user->refresh();
    }
}
