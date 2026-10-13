<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Models\ELearning;
use App\Models\Organization;
use App\Support\Modules\CoursePath;

/**
 * The two addresses an organization hands its people for a course (KOM-95).
 *
 * The same page twice, and deliberately two addresses: one to send as a link, and one that only a
 * QR code ever leads to. That is the whole of how a scan is told apart from a click — the frontend
 * serves both and reports the page it was opened on to Google Analytics, so a visit through
 * `/qr/` was a scan. Nothing here counts anything; it only keeps the two addresses distinct.
 *
 * Both are on the frontend's domain (`FRONTEND_URL`), which serves the page and reads the course
 * from `/api/shared/...` ({@see CoursePath::shared()}).
 */
final class ShareLink
{
    /** `/{organization}/{e-learning}`: the link to send. */
    public static function direct(Organization $organization, ELearning $eLearning): string
    {
        return self::frontend().'/'.rawurlencode($organization->slug).'/'.rawurlencode($eLearning->slug);
    }

    /** `/{organization}/qr/{e-learning}`: what the QR code encodes, and nothing else leads to. */
    public static function qr(Organization $organization, ELearning $eLearning): string
    {
        return self::frontend().'/'.rawurlencode($organization->slug).'/qr/'.rawurlencode($eLearning->slug);
    }

    private static function frontend(): string
    {
        return rtrim((string) config('kompaz.frontend_url'), '/');
    }
}
