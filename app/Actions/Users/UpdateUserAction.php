<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use App\Support\Access\AdministratorCoverage;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Facades\DB;

/**
 * An administrator's edit of somebody else: their name, their address, and — for a platform
 * administrator — their role and which organization they belong to.
 *
 * Deliberately separate from {@see UpdateOwnProfileAction}. Do not let self-service drift into
 * this one by special-casing "is this me" inside it.
 */
final readonly class UpdateUserAction
{
    public function __construct(private AuthenticationTokenService $tokens) {}

    public function execute(
        User $actor,
        User $user,
        string $name,
        string $email,
        ?UserRole $role,
        ?string $organizationId,
    ): User {
        OrganizationAccess::ensureCanManage($actor, $user->organization_id);

        // Checked whatever the request asks for, not only when the role moves: editing a platform
        // administrator at all is reserved, the way deleting one is. Otherwise an ordinary
        // administrator could rename one.
        OrganizationAccess::ensureCanManageRole($actor, $user->role);

        return DB::transaction(function () use ($actor, $user, $name, $email, $role, $organizationId): User {
            if ($organizationId !== null && $organizationId !== $user->organization_id) {
                $this->move($actor, $user, $organizationId, $role);
            } elseif ($role !== null && $role !== $user->role) {
                $this->changeRole($actor, $user, $role);
            }

            $this->changeEmail($user, $email);

            $user->name = trim($name);
            $user->save();

            return $user->refresh();
        });
    }

    /**
     * Moves the user into another organization. Reaching two organizations at once is a platform
     * administrator's privilege, so managing both ends is the whole check.
     */
    private function move(User $actor, User $user, string $destination, ?UserRole $requestedRole): void
    {
        OrganizationAccess::ensureCanManage($actor, $destination);

        // A move resets the role, so a request that also names one is contradicting itself.
        // Refused rather than quietly overruled: silently ignoring half of what somebody asked for
        // is how a caller learns to distrust the response. Promoting them in the new organization
        // is the next request.
        if ($requestedRole !== null && $requestedRole !== UserRole::Member) {
            throw new ConflictException(sprintf(
                'Een gebruiker verplaatsen zet de rol terug naar %s. Ken een hogere rol toe na de verplaatsing.',
                UserRole::Member->value,
            ));
        }

        $organization = Organization::query()->find($destination)
            ?? throw NotFoundException::for('Organization', $destination);

        // Both pools the move empties — the organization they are leaving, and the platform role
        // they give up by leaving it — are checked before anything changes.
        if ($user->role->atLeast(UserRole::Administrator)) {
            AdministratorCoverage::ensureAnAdministratorRemains($user->organization_id, (string) $user->getKey());
        }

        if ($user->role === UserRole::PlatformAdministrator) {
            AdministratorCoverage::ensureAPlatformAdministratorRemains((string) $user->getKey());
        }

        $user->moveTo((string) $organization->getKey());
        $user->setRelation('organization', $organization);

        // Their sessions belong to the organization they were in. Both kinds of credential read
        // the user row on every request, so the move takes effect immediately either way — but they
        // go too, because a session opened inside one organization should not silently continue
        // inside another.
        $this->tokens->revokeAll($user);
    }

    private function changeRole(User $actor, User $user, UserRole $role): void
    {
        // Who administers an organization is the platform's call, so an organization administrator
        // edits names and addresses and nothing else. Granting is looser at invitation time, where
        // a member is being created rather than an existing person moved between roles.
        OrganizationAccess::ensureCanChangeRole($actor);
        OrganizationAccess::ensureCanHoldRole($user->organization, $role);

        if ($user->role === UserRole::PlatformAdministrator) {
            AdministratorCoverage::ensureAPlatformAdministratorRemains((string) $user->getKey());
        }

        // A demotion empties the same pool a deletion would, and leaves the organization just as
        // stuck.
        if ($user->role->atLeast(UserRole::Administrator) && $role->isBelow(UserRole::Administrator)) {
            AdministratorCoverage::ensureAnAdministratorRemains($user->organization_id, (string) $user->getKey());
        }

        $user->role = $role;
    }

    /**
     * Points the account at a new address, which an administrator is trusted to have confirmed
     * belongs to the person in front of them. The unique index has the final say on a duplicate;
     * asking first only buys the caller a message that names the address instead of a bare
     * conflict.
     */
    private function changeEmail(User $user, string $email): void
    {
        $normalized = User::normalize($email);

        if ($normalized === $user->normalized_email) {
            return;
        }

        $taken = User::withTrashed()
            ->whereKeyNot($user->getKey())
            ->where('normalized_email', $normalized)
            ->exists();

        if ($taken) {
            throw new ConflictException(sprintf(
                'Er bestaat al een gebruiker met het e-mailadres "%s".',
                trim($email),
            ));
        }

        // Any link outstanding for this account was emailed to the address they are leaving, and
        // would sign whoever still reads that inbox into an account that is no longer theirs.
        // Sessions are left alone: the person holding them has not changed, only where their post
        // goes.
        LoginToken::query()->where('user_id', $user->getKey())->delete();

        $user->changeEmail($email);
    }
}
