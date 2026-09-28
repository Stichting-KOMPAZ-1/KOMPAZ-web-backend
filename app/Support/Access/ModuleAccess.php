<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Exceptions\ForbiddenAccessException;
use App\Exceptions\NotFoundException;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tenant boundary for content, which is a different question from {@see OrganizationAccess}.
 *
 * A module carries no `organization_id`. It belongs to nobody, and who may read it is decided
 * entirely by whether it has been switched on for the caller's organization — a
 * {@see ModuleActivation} row. Every listing and every detail asks here, for the same reason every
 * user endpoint asks `OrganizationAccess`: a query that forgot would hand somebody another
 * organization's material.
 *
 * **Refusals here are 404, not 403.** Which modules the platform has written is not something a
 * caller at one organization should be able to enumerate, and a 403 on a module that exists tells
 * them it does. This is the opposite of the choice made for organizations, where the caller
 * already knows the organization exists because they were given its identifier.
 */
final class ModuleAccess
{
    /**
     * Every module the caller may read: all of them for the platform, else their own.
     *
     * @return Builder<Module>
     */
    public static function readable(User $user): Builder
    {
        $query = Module::query();

        if (OrganizationAccess::isPlatformAdministrator($user)) {
            return $query;
        }

        return $query->whereHas(
            'activations',
            static fn (Builder $activations): Builder => $activations
                ->where('organization_id', $user->organization_id),
        );
    }

    /**
     * Throws unless the caller may write the platform's own content.
     *
     * Modules and courses are written once for everybody, so authoring them is the platform's job
     * and not a tenant's — an organization administrator runs their own people and adds their own
     * material to a module, but does not decide what the module says or who else gets it.
     */
    public static function ensureCanManageContent(User $user): void
    {
        if (OrganizationAccess::isPlatformAdministrator($user)) {
            return;
        }

        throw new ForbiddenAccessException('Alleen een platformbeheerder kan modules en e-learnings beheren.');
    }

    /** Throws unless the caller may read this module. */
    public static function ensureCanRead(User $user, Module $module): void
    {
        if (OrganizationAccess::isPlatformAdministrator($user)) {
            return;
        }

        if ($module->activationFor($user->organization_id) !== null) {
            return;
        }

        throw new NotFoundException('Deze module bestaat niet of is niet beschikbaar voor deze organisatie.');
    }

    /**
     * The caller's own organization's copy of a module, having first refused them if they may not
     * read it at all.
     *
     * One question and one query, because the two are the same lookup: for everybody but a platform
     * administrator, "may you read this" *is* "do you have an activation". Asking
     * {@see self::ensureCanRead()} and then asking again for the row would run it twice.
     *
     * Null means the caller is a platform administrator, who is not reading the module *at* an
     * organization and so has none of the videos, links or contact details that belong to one.
     * Picking an arbitrary organization's would be picking whose phone number to show them.
     */
    public static function resolveActivation(User $user, Module $module): ?ModuleActivation
    {
        $activation = $module->activationFor($user->organization_id);

        if ($activation !== null) {
            return $activation;
        }

        if (OrganizationAccess::isPlatformAdministrator($user)) {
            return null;
        }

        throw new NotFoundException('Deze module bestaat niet of is niet beschikbaar voor deze organisatie.');
    }

    /**
     * Throws unless the caller may read this course.
     *
     * A course is reachable through the modules that show it, so the question is whether any of
     * those is one the caller may read. A course attached to nothing is reachable by the platform
     * alone — which is correct: it exists, but nobody has been given it yet.
     */
    public static function ensureCanReadCourse(User $user, ELearning $eLearning): void
    {
        if (OrganizationAccess::isPlatformAdministrator($user)) {
            return;
        }

        $reachable = $eLearning->modules()
            ->whereHas(
                'activations',
                static fn (Builder $activations): Builder => $activations
                    ->where('organization_id', $user->organization_id),
            )
            ->exists();

        if ($reachable) {
            return;
        }

        throw new NotFoundException('Deze e-learning bestaat niet of is niet beschikbaar voor deze organisatie.');
    }
}
