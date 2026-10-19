<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use App\Models\Organization;
use App\Support\Links\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as it appears on a module: enough to link to it, and nothing of its contents.
 *
 * And, for a reader at an organization, what they hand their patients for it (KOM-81): the link,
 * the address only its QR code leads to, and that QR code itself. The organization is the reader's
 * own, because a course is shared by the organization it reached; a platform administrator reads
 * a module at no organization, so for them the three are null, and they pick the organization at
 * `/api/e-learnings/{eLearning}/shares` instead ({@see ELearningShareResource}).
 *
 * @mixin ELearning
 */
final class ELearningSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  Organization|null  $sharedBy  the reader's organization, which shares the course
     * @param  string  $moduleId  the module the course was reached through, which its QR code is under
     */
    public function __construct(ELearning $eLearning, private readonly ?Organization $sharedBy, private readonly string $moduleId)
    {
        parent::__construct($eLearning);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sharedBy = $this->sharedBy;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => '/api/e-learnings/'.$this->id.'/image',
            'chapterCount' => (int) ($this->chapters_count ?? 0),

            // The link to copy and send, on the frontend's domain.
            'shareUrl' => $sharedBy === null ? null : ShareLink::direct($sharedBy, $this->resource),

            // What the QR code encodes. Never shown to copy: an address only a scan leads to is how
            // a scan is told apart from a click.
            'qrShareUrl' => $sharedBy === null ? null : ShareLink::qr($sharedBy, $this->resource),

            // The QR code as a PNG; `?download` to have the browser save it.
            'qrCodeUrl' => $sharedBy === null ? null : '/api/modules/'.$this->moduleId.'/e-learnings/'.$this->id.'/qr-code',
        ];
    }
}
