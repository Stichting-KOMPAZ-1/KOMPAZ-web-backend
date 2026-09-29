<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One block of a step.
 *
 * Every key is present for every kind, null where the kind does not use it, so a client switches on
 * `type` rather than on which fields it happens to find. The table already guarantees that the ones
 * a kind does use are filled in.
 *
 * @mixin ContentBlock
 */
final class ContentBlockResource extends JsonResource
{
    public static $wrap = null;

    /** @param  string  $eLearningId  the course this block was reached through, which is where the reader's permission comes from */
    public function __construct(ContentBlock $block, private readonly string $eLearningId)
    {
        parent::__construct($block);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'position' => $this->position,

            // Text only, and plain: nothing stored here is markup, so nothing reading it should
            // render it as such.
            'body' => $this->body,

            // A video that was linked rather than uploaded. Exactly one of this and `fileUrl` is
            // set on a video block, which is what the table's constraint says.
            'videoUrl' => $this->video_url,

            'fileUrl' => $this->file() === null ? null : $this->fileUrl(),
        ];
    }

    /** Where a picture's or an uploaded video's bytes are read back from. */
    private function fileUrl(): string
    {
        return sprintf(
            '/api/e-learnings/%s/steps/%s/blocks/%s/file',
            $this->eLearningId,
            $this->step_id,
            $this->id,
        );
    }
}
