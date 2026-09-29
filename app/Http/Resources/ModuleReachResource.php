<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleActivation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organization a module is switched on for, as the platform's "Actief bij" lists it.
 *
 * @mixin ModuleActivation
 */
final class ModuleReachResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'organizationId' => $this->organization_id,
            'organizationName' => $this->organization->name,
            'hasContactDetails' => $this->hasContactDetails(),
            /** @format date-time */
            'activatedUtc' => $this->activated_at->toIso8601String(),
        ];
    }
}
