<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenAccessException;
use App\Models\Organization;
use App\Models\User;

/**
 * The tenant boundary. A platform administrator reaches every organization; everybody else only
 * their own.
 *
 * Every handler asks here rather than deciding for itself, because a role floor on a route is a
 * statement about seniority and never about which tenant the caller belongs to. The two questions
 * are separate and both have to be answered.
 */
final class OrganizationAccess
{
    public static function isPlatformAdministrator(User $user): bool
    {
        return $user->role === UserRole::PlatformAdministrator;
    }

    /** Throws unless the caller may read the given organization. */
    public static function ensureCanRead(User $user, string $organizationId): void
    {
        if (self::isPlatformAdministrator($user) || $user->organization_id === $organizationId) {
            return;
        }

        throw new ForbiddenAccessException('Deze gebruiker hoort niet bij de opgevraagde organisatie.');
    }

    /**
     * Whether the caller may change the given organization or the people inside it.
     *
     * The question is normally asked by {@see self::ensureCanManage()}, which refuses on a no. It
     * is answerable on its own for the one caller that has to weigh the answer rather than act on
     * it: an invitation deciding whether the address it was handed is free, where a refusal has to
     * read the same whatever stands in the way, so that it discloses nothing about who exists.
     */
    public static function canManage(User $user, string $organizationId): bool
    {
        $managesOwn = $user->organization_id === $organizationId
            && $user->role->atLeast(UserRole::Administrator);

        return self::isPlatformAdministrator($user) || $managesOwn;
    }

    /** Throws unless the caller may change the given organization or the people inside it. */
    public static function ensureCanManage(User $user, string $organizationId): void
    {
        if (self::canManage($user, $organizationId)) {
            return;
        }

        throw new ForbiddenAccessException('Deze gebruiker is geen beheerder van de opgevraagde organisatie.');
    }

    /**
     * Throws unless the caller may grant the given role, or act on somebody who already holds it.
     * Only a platform administrator can create, demote or remove another platform administrator.
     */
    public static function ensureCanManageRole(User $user, UserRole $role): void
    {
        if ($role !== UserRole::PlatformAdministrator || self::isPlatformAdministrator($user)) {
            return;
        }

        throw new ForbiddenAccessException('Alleen een platformbeheerder kan de rol platformbeheerder beheren.');
    }

    /**
     * Throws unless the caller may change somebody's role at all.
     *
     * Reserved to platform administrators, which is stricter than {@see self::ensureCanGrantRole()}
     * and deliberately so: an organization administrator runs the people in their organization, but
     * who administers it is the platform's call. Inviting is the looser of the two, because
     * inviting somebody creates them rather than moving an existing person between roles.
     */
    public static function ensureCanChangeRole(User $user): void
    {
        if (self::isPlatformAdministrator($user)) {
            return;
        }

        throw new ForbiddenAccessException('Alleen een platformbeheerder kan de rol van iemand wijzigen.');
    }

    /**
     * Throws unless the caller may hand the given role to somebody else.
     *
     * Managing a role and granting it are not the same thing, but granting is not reserved to the
     * level above either: an organization administrator may invite a fellow administrator into
     * their own organization, because the product wants an organization to be able to run itself.
     * What nobody can do is hand out a role above their own — which, roles being hierarchical,
     * keeps the platform administrator role with the platform.
     */
    public static function ensureCanGrantRole(User $user, UserRole $role): void
    {
        // Keeps the more specific message for the escalation everybody tries first.
        self::ensureCanManageRole($user, $role);

        if (self::canGrantRole($user, $role)) {
            return;
        }

        throw new ForbiddenAccessException('Deze gebruiker kan geen hogere rol dan de eigen rol toekennen.');
    }

    /**
     * Whether the caller may hand the given role to somebody else: any role up to and including
     * their own. Asked by {@see self::ensureCanGrantRole()} and by every form that offers a role to
     * grant, so a picker never lists a role the use case behind it would refuse.
     */
    public static function canGrantRole(User $user, UserRole $role): bool
    {
        return $user->role->atLeast($role);
    }

    /**
     * The roles the caller may hand to somebody else, as a select's options.
     *
     * @return array<string, string>
     */
    public static function grantableRoleOptions(User $user): array
    {
        return array_filter(
            UserRole::options(),
            static fn (string $value): bool => self::canGrantRole($user, UserRole::from($value)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Throws unless the organization may hold the given role. Not a permission check — the caller
     * is allowed to grant the role, just not there — so it reports a clash rather than a refusal.
     */
    public static function ensureCanHoldRole(Organization $organization, UserRole $role): void
    {
        if ($organization->canHold($role)) {
            return;
        }

        throw new ConflictException(sprintf(
            'Een platformbeheerder hoort bij de organisatie die het platform beheert, niet bij "%s".',
            $organization->name,
        ));
    }

    /**
     * Resolves the organization a request targets, defaulting to the caller's own when none is
     * supplied.
     *
     * Every user belongs to exactly one organization — the column is not nullable and the foreign
     * key is enforced — so there is no "no organization" case left to answer here.
     */
    public static function resolveTarget(User $user, ?string $requestedOrganizationId): string
    {
        if ($requestedOrganizationId !== null && $requestedOrganizationId !== '') {
            return $requestedOrganizationId;
        }

        return $user->organization_id;
    }
}
