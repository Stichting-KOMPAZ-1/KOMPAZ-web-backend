<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Support\Modules\CoursePath;
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

    /** @param  string  $coursePath  where the course this part was reached through is read ({@see CoursePath}), which its blocks address their files under */
    public function __construct(Step $step, private readonly string $coursePath)
    {
        parent::__construct($step);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // A "Stap" block's number is its place among the steps on this part (KOM-99), which only
        // the part can count.
        $steps = 0;

        return [
            'id' => $this->id,
            'chapterId' => $this->chapter_id,
            'name' => $this->name,
            'position' => $this->position,
            'blocks' => $this->blocks
                ->map(function (ContentBlock $block) use (&$steps): ContentBlockResource {
                    $number = $block->type === ContentBlockType::Instruction ? ++$steps : null;

                    return new ContentBlockResource($block, $this->coursePath, $number);
                })
                ->values()
                ->all(),
        ];
    }
}
