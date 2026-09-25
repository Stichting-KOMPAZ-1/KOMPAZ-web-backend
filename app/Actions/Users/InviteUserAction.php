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
use App\Support\Access\AdministratorCoverage;
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
     * A deleted user is reached the same way and comes back as a fresh invitation, in the
     * organization this invitation names rather than only in the one they were deleted from.
     * Their row is still here holding the address, so the alternative would be that deleting
     * somebody burns their email address for good — everywhere, since the address is unique across
     * the whole table and not within an organization. Reusing the row also keeps every log and
     * audit entry pointing at one person instead of splitting them across two identifiers.
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
        if (self::clashes($actor, $existing, $organizationId)) {
            throw new ConflictException(sprintf(
                'Er bestaat al een gebruiker met het e-mailadres "%s".',
                trim($email),
            ));
        }

        OrganizationAccess::ensureCanManageRole($actor, $existing->role);

        if ($existing->isDeleted()) {
            $existing->reviveAsInvited($organizationId, $name, $role, $now);
            $existing->save();

            return $existing;
        }

        $existing->name = trim($name);
        $existing->role = $role;
        $existing->invited_at = $now;
        $existing->save();

        return $existing;
    }

    /**
     * Whether the row holding this address is a person the invitation cannot have.
     *
     * Somebody still here is theirs: their own organization can re-invite them while the
     * invitation is outstanding, and nobody can take them off another organization's roster by
     * inviting the address out from under it.
     *
     * A deleted row is not a person but a tombstone holding an address, so an invitation takes it
     * over instead of being refused by it. Taking one over from another organization is a move,
     * and a move needs both ends — which is why the caller has to manage the organization the
     * tombstone sits in as well as the one they are inviting into. Failing that it reads as an
     * address in use, exactly like a live row, because a caller who may not see that organization
     * must not be able to tell the two apart. Nothing is emptied by the move: a deleted
     * administrator has already stopped counting towards {@see AdministratorCoverage}, and their
     * credentials went with the deletion.
     */
    private static function clashes(User $actor, User $existing, string $organizationId): bool
    {
        if (! $existing->isDeleted()) {
            return $existing->organization_id !== $organizationId
                || $existing->status === UserStatus::Active;
        }

        return $existing->organization_id !== $organizationId
            && ! OrganizationAccess::canManage($actor, $existing->organization_id);
    }
}
