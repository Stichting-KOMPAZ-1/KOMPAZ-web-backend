<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Models\Organization;
use App\Support\Images\ServedLogo;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The logo an organization is shown with, for the panel's own pages.
 *
 * The API's logo endpoint is unreachable from here: an `<img src>` sends the session cookie and
 * cannot send a bearer token. So the same bytes under the same headers, behind the guard the rest
 * of the panel is behind — a session, and the `viewNova` gate on the route.
 *
 * That gate is the whole check. It passes for platform administrators only, and a platform
 * administrator may read every organization, so there is no tenant question left for this to ask.
 */
final readonly class NovaOrganizationLogoController
{
    public function show(Organization $organization): Response
    {
        $logo = ServedLogo::for($organization);

        return response($logo->content, HttpResponse::HTTP_OK, $logo->headers());
    }
}
