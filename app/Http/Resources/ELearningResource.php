<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course with its contents: every chapter, and the name of every step inside them.
 *
 * The whole tree in one response, because that is what the sidebar of a step page shows and asking
 * for it a chapter at a time would be a request per heading. What is deliberately absent is the
 * content of the steps themselves — those are fetched one at a time, as they are read.
 *
 * @mixin ELearning
 */
final class ELearningResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => '/api/e-learnings/'.$this->id.'/image',
            'chapters' => ChapterResource::collection($this->chapters)->toArray($request),
            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
    }
}
