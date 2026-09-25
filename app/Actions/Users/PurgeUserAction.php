<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\UserDeleted;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use App\Support\Access\AdministratorCoverage;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user for good: the row itself, not a mark on it.
 *
 * The counterpart of {@see DeleteUserAction}, which keeps the row precisely so the person can come
 * back and so the identifier on every audit column still resolves to somebody. This gives that up
 * on purpose, for the two cases where keeping the row is the wrong answer: an account that should
 * never have existed, and an address somebody needs released for good. What is left behind is
 * `created_by` and `updated_by` values pointing at an identifier with no row under it — those
 * columns carry no foreign key, so nothing breaks, but the trail stops naming this person.
 *
 * Only the panel calls this. The API deletes as it always has, reversibly, because a client that
 * can be wrong about a request should not be able to be irreversibly wrong.
 *
 * Every credential is ended first and explicitly. The sign-in links go with the row — their
 * foreign key cascades — but tokens are stored under a morph and sessions under a plain column,
 * so both would otherwise be rows pointing at a user who no longer exists.
 */
final readonly class PurgeUserAction
{
    public function __construct(private AuthenticationTokenService $tokens) {}

    public function execute(User $actor, User $user): void
    {
        OrganizationAccess::ensureCanManage($actor, $user->organization_id);
        OrganizationAccess::ensureCanManageRole($actor, $user->role);

        if ($actor->getKey() === $user->getKey()) {
            throw new ConflictException('Een gebruiker kan het eigen account niet verwijderen.');
        }

        // Asked only of somebody still counted among the administrators. One who was deleted left
        // that pool then, and the question was answered then; asking it again here would refuse to
        // remove the very row whose deletion is the reason nobody is left.
        if (! $user->isDeleted() && $user->role->atLeast(UserRole::Administrator)) {
            AdministratorCoverage::ensureAnAdministratorRemains($user->organization_id, (string) $user->getKey());
        }

        DB::transaction(function () use ($user): void {
            // Told only if there was something left to lose. Somebody already deleted has had this
            // notice, and their account has been gone from their side since; somebody who never
            // accepted their invitation has no account for a notice to be about. Both are the same
            // silence {@see DeleteUserAction} keeps, for the same reasons.
            $announce = ! $user->isDeleted() && $user->status !== UserStatus::Invited;

            $this->tokens->revokeAll($user);

            $user->forceDelete();

            if ($announce) {
                UserDeleted::dispatch((string) $user->getKey(), $user->email, $user->name);
            }
        });
    }
}
