<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Exceptions\ConflictException;
use App\Models\User;

/**
 * The two "somebody has to be left" invariants, in one place because three commands can break them.
 *
 * An organization or a platform with nobody able to administer it cannot fix itself: only an
 * administrator can invite, and only an administrator can appoint one. Deleting, demoting and
 * moving somebody all take a person out of that pool, so all three ask here rather than each
 * remembering the rule for itself. A fourth way to remove somebody asks here too.
 */
final class AdministratorCoverage
{
    /**
     * Throws when taking this person out of an organization's administrators would leave it with
     * none. The caller decides whether they are leaving it — by deletion, demotion or a move — and
     * only asks when they are.
     */
    public static function ensureAnAdministratorRemains(string $organizationId, string $leavingUserId): void
    {
        $anotherRemains = User::query()
            ->whereKeyNot($leavingUserId)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('role', [UserRole::Administrator->value, UserRole::PlatformAdministrator->value])
            ->exists();

        if (! $anotherRemains) {
            throw new ConflictException(
                'Een organisatie kan niet zonder beheerder achterblijven. Wijs eerst een andere beheerder aan.',
            );
        }
    }

    /**
     * Throws when taking this person out of the platform administrators would leave nobody able to
     * grant the role back. A deleted one does not count as remaining: they cannot sign in, so they
     * could not appoint anybody.
     */
    public static function ensureAPlatformAdministratorRemains(string $leavingUserId): void
    {
        $anotherRemains = User::query()
            ->whereKeyNot($leavingUserId)
            ->whereNull('deleted_at')
            ->where('role', UserRole::PlatformAdministrator->value)
            ->exists();

        if (! $anotherRemains) {
            throw new ConflictException(
                'De laatste platformbeheerder kan de rol niet opgeven. Wijs eerst een andere platformbeheerder aan.',
            );
        }
    }
}
