<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\UserDeleted;
use App\Exceptions\ConflictException;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use App\Support\Access\AdministratorCoverage;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user: they lose access at once and are told by email, and an administrator can undo it.
 *
 * The row is kept and marked rather than removed. Three things need it to survive: restoring the
 * user has to know what to put back, the audit trail on every other table refers to people by
 * identifier, and the address is the unique key — so removing the row for real would burn the email
 * address and orphan the history at the same time.
 */
final readonly class DeleteUserAction
{
    public function __construct(private AuthenticationTokenService $tokens) {}

    public function execute(User $actor, User $user): void
    {
        OrganizationAccess::ensureCanManage($actor, $user->organization_id);
        OrganizationAccess::ensureCanManageRole($actor, $user->role);

        if ($actor->getKey() === $user->getKey()) {
            throw new ConflictException('Een gebruiker kan het eigen account niet verwijderen.');
        }

        if ($user->role->atLeast(UserRole::Administrator)) {
            AdministratorCoverage::ensureAnAdministratorRemains($user->organization_id, (string) $user->getKey());
        }

        DB::transaction(function () use ($user): void {
            // Deleted outright, not marked. These are credentials: a sign-in link in an inbox, a
            // token in a client or a session cookie in a browser would otherwise still be
            // presentable. Restoring the user does not bring them back — they sign in again from
            // the login page.
            LoginToken::query()->where('user_id', $user->getKey())->delete();
            $this->tokens->revokeAll($user);

            // Only somebody who could actually sign in is told their account is gone. The notice
            // says their account is deleted and that they can no longer log in, and for an invited
            // user every line of that is untrue: they never had an account and never could log in.
            // What they lose is a link they may not have opened, so revoking an invitation is
            // silent.
            $tellThem = $user->status === UserStatus::Active;

            $user->delete();

            if ($tellThem) {
                UserDeleted::dispatch((string) $user->getKey(), $user->email, $user->name);
            }
        });
    }
}
