<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as a row of the course table: how big it is, and which modules show it.
 *
 * The modules are the ones the reader may see, which for anybody but the platform is the ones
 * switched on for their organization: a course shown by another organization's module does not
 * tell them that module exists.
 *
 * @mixin ELearning
 */
final class ELearningListResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => '/api/e-learnings/'.$this->id.'/image',
            'chapterCount' => (int) ($this->chapters_count ?? 0),
            'modules' => ModuleReferenceResource::collection($this->modules)->toArray($request),
            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
    }
}
