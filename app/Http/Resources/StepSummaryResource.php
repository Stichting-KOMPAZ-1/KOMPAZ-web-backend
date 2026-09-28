<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Step;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A step as it appears in a course's contents: its name and where it sits, with none of what is on
 * it.
 *
 * The whole tree is answered at once so the sidebar can be drawn, and a tree carrying every block
 * of every step would be the course's entire content on the first request.
 *
 * @mixin Step
 */
final class StepSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
        ];
    }
}
