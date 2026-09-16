<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\InvitationIssued;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoginTokenIssuer;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invites somebody into an organization and emails them a link that accepts the invitation and
 * signs them in.
 */
final readonly class InviteUserAction
{
    public function __construct(private LoginTokenIssuer $tokenIssuer) {}

    public function execute(
        User $actor,
        string $email,
        string $name,
        UserRole $role,
        ?string $organizationId,
    ): User {
        $targetOrganizationId = OrganizationAccess::resolveTarget($actor, $organizationId);
        OrganizationAccess::ensureCanManage($actor, $targetOrganizationId);
        OrganizationAccess::ensureCanGrantRole($actor, $role);

        $organization = Organization::query()->find($targetOrganizationId)
            ?? throw NotFoundException::for('Organization', $targetOrganizationId);

        OrganizationAccess::ensureCanHoldRole($organization, $role);

        return DB::transaction(function () use ($actor, $email, $name, $role, $organization): User {
            $now = Carbon::now();

            // Deliberately reaches past the soft-delete filter. A deleted user still holds the
            // address, which is the unique key, so this is the one query besides restoring that
            // has to see them — and see them loudly, rather than create a second row the unique
            // index would refuse anyway.
            $existing = User::withTrashed()
                ->where('normalized_email', User::normalize($email))
                ->first();

            $user = $existing === null
                ? $this->addInvitedUser($organization->getKey(), $email, $name, $role, $now)
                : $this->renewInvitation($actor, $existing, $organization->getKey(), $email, $name, $role, $now);

            $token = $this->tokenIssuer->issue($user, LoginTokenPurpose::Invitation);

            InvitationIssued::dispatch(
                (string) $user->getKey(),
                $user->email,
                $user->name,
                $organization->name,
                $token,
            );

            return $user;
        });
    }

    private function addInvitedUser(
        string $organizationId,
        string $email,
        string $name,
        UserRole $role,
        Carbon $now,
    ): User {
        $user = new User;
        $user->organization_id = $organizationId;
        $user->email = trim($email);
        $user->normalized_email = User::normalize($email);
        $user->name = trim($name);
        $user->role = $role;
        $user->status = UserStatus::Invited;
        $user->invited_at = $now;
        $user->save();

        return $user;
    }

    /**
     * Re-invites somebody who has not accepted yet, rather than refusing the address outright.
     *
     * The user row commits before the invitation email goes out, so a send that fails would
     * otherwise leave a user who was never told and an address the administrator can no longer
     * invite. Repeating the request is the obvious recovery, so it works.
     *
     * A deleted user is reached the same way and comes back as a fresh invitation. Their row is
     * still here holding the address, so the alternative would be that deleting somebody burns
     * their email address for good. Reusing the row also keeps every log and audit entry pointing
     * at one person instead of splitting them across two identifiers.
     *
     * An address belonging to somebody who has already signed in and is still here is a genuine
     * clash and is still refused, as is one in an organization the caller did not name — with the
     * message the caller would have got either way, so this discloses nothing new about who exists.
     */
    private function renewInvitation(
        User $actor,
        User $existing,
        string $organizationId,
        string $email,
        string $name,
        UserRole $role,
        Carbon $now,
    ): User {
        $clashes = $existing->organization_id !== $organizationId
            || ($existing->status === UserStatus::Active && ! $existing->isDeleted());

        if ($clashes) {
            throw new ConflictException(sprintf(
                'Er bestaat al een gebruiker met het e-mailadres "%s".',
                trim($email),
            ));
        }

        OrganizationAccess::ensureCanManageRole($actor, $existing->role);

        if ($existing->isDeleted()) {
            $existing->reviveAsInvited($name, $role, $now);
            $existing->save();

            return $existing;
        }

        $existing->name = trim($name);
        $existing->role = $role;
        $existing->invited_at = $now;
        $existing->save();

        return $existing;
    }
}
