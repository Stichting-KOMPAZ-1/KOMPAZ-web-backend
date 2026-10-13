<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Models\ELearning;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Access\OrganizationAccess;
use App\Support\Links\QrCodeImage;
use App\Support\Links\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The QR code for a course as one organization shares it, for the panel's "Delen" dialog (KOM-95).
 *
 * Shown in the dialog and downloaded from it, so it is the same image both times: `?download`
 * only changes whether the browser saves it or draws it. A route of the panel's own rather than
 * the API's for the reason a picture's is — the dialog's `<img>` and its download link carry the
 * session cookie, not a token — and the platform's alone, because sharing a course is.
 *
 * The code encodes the QR address, never the link: a scan has to arrive on `/qr/` to be counted
 * as one ({@see ShareLink}).
 */
final readonly class NovaShareQrCodeController
{
    public function show(Request $request, ELearning $eLearning, Organization $organization): Response
    {
        $operator = $request->user();

        // Refused through Laravel's own HTTP errors rather than the application's exceptions: this
        // is read by an `<img>` and a download link, which ask for no JSON, and the application's
        // refusals are only turned into their status for a request that does.
        abort_unless(
            $operator instanceof User && OrganizationAccess::isPlatformAdministrator($operator),
            Response::HTTP_FORBIDDEN,
            'Alleen een platformbeheerder kan e-learnings delen.',
        );

        abort_unless(
            ModuleAccess::organizationsSharing($eLearning)->whereKey($organization->getKey())->exists(),
            Response::HTTP_NOT_FOUND,
            'Deze e-learning is niet beschikbaar voor deze organisatie.',
        );

        $disposition = $request->has('download') ? 'attachment' : 'inline';
        $fileName = sprintf('qr-%s-%s.png', $organization->slug, $eLearning->slug);

        return new Response(QrCodeImage::png(ShareLink::qr($organization, $eLearning)), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $fileName),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
