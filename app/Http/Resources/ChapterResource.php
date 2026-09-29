<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Chapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chapter and the names of its steps, in the order an operator arranged them.
 *
 * @mixin Chapter
 */
final class ChapterResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,

            // The front end draws a summary chapter differently. Several are allowed, so this is a
            // property of each chapter rather than a pointer to one of them.
            'isSummary' => $this->is_summary,

            'position' => $this->position,
            'steps' => StepSummaryResource::collection($this->steps)->toArray($request),
        ];
    }
}
