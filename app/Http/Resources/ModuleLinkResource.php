<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extra link on a module.
 *
 * `isOrganizationSpecific` says which of the two lists it came from, because the front end shows
 * them together but an organization administrator only ever edits their own.
 *
 * @mixin ModuleLink
 */
final class ModuleLinkResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'isOrganizationSpecific' => $this->module_activation_id !== null,
        ];
    }
}
