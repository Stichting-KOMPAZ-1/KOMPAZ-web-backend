<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use App\Models\Organization;
use App\Support\Links\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as one organization shares it, for the platform's share dialog (KOM-94).
 *
 * The addresses are built here rather than by the client, which is never handed a slug: they are
 * printed in QR codes, and one place deciding their shape is what keeps a printed code answering.
 *
 * @mixin Organization
 */
final class ELearningShareResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Organization $organization, private readonly ELearning $eLearning)
    {
        parent::__construct($organization);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'organizationId' => $this->id,
            'organizationName' => $this->name,

            // The link to copy and send, on the frontend's domain.
            'shareUrl' => ShareLink::direct($this->resource, $this->eLearning),

            // What the QR code encodes. Never shown to copy: an address only a scan leads to is how
            // a scan is told apart from a click.
            'qrShareUrl' => ShareLink::qr($this->resource, $this->eLearning),

            // The QR code as a PNG; `?download` to have the browser save it.
            'qrCodeUrl' => '/api/e-learnings/'.$this->eLearning->id.'/shares/'.$this->id.'/qr-code',
        ];
    }
}
