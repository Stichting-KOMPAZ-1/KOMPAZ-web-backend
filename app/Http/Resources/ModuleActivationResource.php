<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organization's own copy of a module: what it added, and only that.
 *
 * The editing view of what {@see ModuleResource} merges into the platform's material for a
 * reader. Nothing of the platform's is here, so a form built on it writes back exactly what it
 * was given.
 *
 * @mixin ModuleActivation
 */
final class ModuleActivationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'moduleId' => $this->module_id,
            'organizationId' => $this->organization_id,
            'videos' => $this->videos
                ->map(fn (ModuleVideo $video): ModuleVideoResource => new ModuleVideoResource($video, $this->module_id))
                ->values()
                ->all(),
            'links' => ModuleLinkResource::collection($this->links)->toArray($request),
            'contacts' => ModuleContactResource::collection($this->contacts)->toArray($request),
            /** @format date-time */
            'activatedUtc' => $this->activated_at->toIso8601String(),
        ];
    }
}
