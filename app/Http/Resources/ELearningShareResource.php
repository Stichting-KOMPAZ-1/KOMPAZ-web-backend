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
 * A course as one organization shares it, for the platform's share dialog (KOM-94).
 *
 * The addresses are built here rather than by the client: they are printed in QR codes, and one
 * place deciding their shape is what keeps a printed code answering. The slugs come alongside, for
 * a client that routes to the shared page itself ({@see ShareLink::slugs()}).
 *
 * @mixin Organization
 */
final class ELearningShareResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Organization $organization,
        private readonly Module $module,
        private readonly ELearning $eLearning,
    ) {
        parent::__construct($organization);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'organizationId' => $this->id,
            'organizationName' => $this->name,
            'moduleId' => $this->module->getKey(),
            'moduleName' => $this->module->name,

            // The link to copy and send, on the frontend's domain.
            'shareUrl' => ShareLink::direct($this->resource, $this->module, $this->eLearning),

            // What `shareUrl` is made of, for a route to the shared page; never parse the link.
            'shareSlugs' => ShareLink::slugs($this->resource, $this->module, $this->eLearning),

            // What the QR code encodes: the link with `utm_source=qr`, which is how a scan is told
            // apart from a click. Never shown to copy, or a click would be counted as a scan.
            'qrShareUrl' => ShareLink::qr($this->resource, $this->module, $this->eLearning),

            // The QR code as a PNG; `?download` to have the browser save it.
            'qrCodeUrl' => '/api/e-learnings/'.$this->eLearning->id.'/shares/'.$this->id.'/'.$this->module->getKey().'/qr-code',
        ];
    }
}
