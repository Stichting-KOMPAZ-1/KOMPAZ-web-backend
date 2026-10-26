<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\Organization;

/**
 * Where a course is read from in the API, which is also where its pictures and videos are.
 *
 * Two doors, one course. A signed-in reader reaches it by its identifier and is asked who they are;
 * a visitor who followed a shared link (KOM-95) reaches it through the organization that shared it,
 * by slug and with no account. Every address in a course's response — its picture, a block's file —
 * hangs off the path it was read through, so a visitor is never handed an address that asks them
 * to sign in.
 */
final class CoursePath
{
    /** `/api/e-learnings/{id}`: for a signed-in reader. */
    public static function signedIn(ELearning $eLearning): string
    {
        return '/api/e-learnings/'.$eLearning->getKey();
    }

    /** The public API path matching either a current module link or a legacy link. */
    public static function shared(Organization $organization, ELearning $eLearning, ?Module $module = null): string
    {
        $moduleSegment = $module === null ? '' : '/'.rawurlencode($module->slug);

        return '/api/shared/'.rawurlencode($organization->slug).$moduleSegment.'/'.rawurlencode($eLearning->slug);
    }
}
