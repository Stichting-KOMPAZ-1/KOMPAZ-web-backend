<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\Organization;
use App\Support\Modules\CoursePath;

/**
 * The two addresses an organization hands its people for a course in a module (KOM-95).
 *
 * One page, and one address for it: the QR code encodes the same link with campaign parameters
 * (`utm_source=qr`), which is how Google Analytics on the frontend tells a scan from a click
 * (KOM-110). It used to lead to a page of its own under `/qr/`, which made two addresses for one
 * course — and two counted visits per scan once the public page recorded its own. Nothing here
 * counts anything.
 *
 * Under `/delen/`, so a shared link is never mistaken for one of the frontend's own pages: without
 * it, any two-segment address the frontend did not know — `/profiel/x` — was read as an
 * organization and a course.
 *
 * Both are on the frontend's domain (`FRONTEND_URL`), which serves the page and reads the course
 * from `/api/shared/...` ({@see CoursePath::shared()}).
 *
 * The slugs are handed out beside the link ({@see self::slugs()}), so a client that routes to the
 * page itself fills in its own route rather than parsing the link back apart — a change to the
 * link's shape would otherwise break those routes without an error anywhere.
 */
final class ShareLink
{
    /** `/delen/{organization}/{module}/{e-learning}`: the link to send. */
    public static function direct(Organization $organization, Module $module, ELearning $eLearning): string
    {
        return self::frontend().'/delen/'.rawurlencode($organization->slug).'/'.rawurlencode($module->slug).'/'.rawurlencode($eLearning->slug);
    }

    /** The link with `?utm_source=qr&utm_medium=qr`: what the QR code encodes. */
    public static function qr(Organization $organization, Module $module, ELearning $eLearning): string
    {
        return self::direct($organization, $module, $eLearning).'?'.http_build_query(['utm_source' => 'qr', 'utm_medium' => 'qr']);
    }

    /**
     * The three segments the link is made of, by name.
     *
     * @return array{organization: string, module: string, eLearning: string}
     */
    public static function slugs(Organization $organization, Module $module, ELearning $eLearning): array
    {
        return [
            'organization' => $organization->slug,
            'module' => $module->slug,
            'eLearning' => $eLearning->slug,
        ];
    }

    private static function frontend(): string
    {
        return rtrim((string) config('kompaz.frontend_url'), '/');
    }
}
