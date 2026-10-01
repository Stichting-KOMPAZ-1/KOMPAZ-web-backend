<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentBlock;
use App\Models\Step;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One part with everything on it, in the order it is drawn. A part is a {@see Step} by its old
 * name, which the code and the table keep.
 *
 * @mixin Step
 */
final class PartResource extends JsonResource
{
    public static $wrap = null;

    /** @param  string  $eLearningId  the course this part was reached through, which its blocks need to address their files */
    public function __construct(Step $step, private readonly string $eLearningId)
    {
        parent::__construct($step);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chapterId' => $this->chapter_id,
            'name' => $this->name,
            'position' => $this->position,
            'blocks' => $this->blocks
                ->map(fn (ContentBlock $block): ContentBlockResource => new ContentBlockResource($block, $this->eLearningId))
                ->values()
                ->all(),
        ];
    }
}
