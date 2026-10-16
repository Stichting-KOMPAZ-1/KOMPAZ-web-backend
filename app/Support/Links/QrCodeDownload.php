<?php

declare(strict_types=1);

namespace App\Support\Links;

use App\Models\ELearning;
use App\Models\Organization;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The QR code for a course as one organization shares it, as a PNG (KOM-95, KOM-81).
 *
 * The same image whoever asks for it and through whichever door — the panel's "Delen" dialog or
 * a care professional on the module page — so both answer with this. The code encodes the QR
 * address, never the link: a scan has to arrive on `/qr/` to be told apart from a click
 * ({@see ShareLink}). `download` only decides whether a browser saves the image or draws it.
 *
 * Whether the course is shared with that organization is the caller's to have asked.
 */
final readonly class QrCodeDownload implements Responsable
{
    public function __construct(
        private Organization $organization,
        private ELearning $eLearning,
        private bool $download,
    ) {}

    public function toResponse($request): Response
    {
        $fileName = sprintf('qr-%s-%s.png', $this->organization->slug, $this->eLearning->slug);

        return new Response(QrCodeImage::png(ShareLink::qr($this->organization, $this->eLearning)), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('%s; filename="%s"', $this->download ? 'attachment' : 'inline', $fileName),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Whether the request asked for the image to be saved rather than drawn: `?download`. */
    public static function asksToDownload(Request $request): bool
    {
        return $request->has('download');
    }
}
