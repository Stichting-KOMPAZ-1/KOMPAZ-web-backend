<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use App\Models\Module;
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
     * @param  Module  $module  the module the course was reached through, which its links name
     * @param  string|null  $addressedAs  the organization the module was read as by name, which the QR code is then addressed under
     */
    public function __construct(
        ELearning $eLearning,
        private readonly ?Organization $sharedBy,
        private readonly Module $module,
        private readonly ?string $addressedAs = null,
    ) {
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
            'shareUrl' => $sharedBy === null ? null : ShareLink::direct($sharedBy, $this->module, $this->resource),

            // What `shareUrl` is made of, for a route to the shared page; never parse the link.
            'shareSlugs' => $sharedBy === null ? null : ShareLink::slugs($sharedBy, $this->module, $this->resource),

            // What the QR code encodes: the link with `utm_source=qr`, which is how a scan is told
            // apart from a click. Never shown to copy, or a click would be counted as a scan.
            'qrShareUrl' => $sharedBy === null ? null : ShareLink::qr($sharedBy, $this->module, $this->resource),

            // The QR code as a PNG; `?download` to have the browser save it.
            // Under the organization when the module was read as it by name, so the platform gets the
            // same code that organization hands out. Never a query string: a client adds `?download`.
            'qrCodeUrl' => match (true) {
                $sharedBy === null => null,
                $this->addressedAs !== null => '/api/modules/'.$this->module->getKey().'/organizations/'.$this->addressedAs.'/e-learnings/'.$this->id.'/qr-code',
                default => '/api/modules/'.$this->module->getKey().'/e-learnings/'.$this->id.'/qr-code',
            },
        ];
    }
}
