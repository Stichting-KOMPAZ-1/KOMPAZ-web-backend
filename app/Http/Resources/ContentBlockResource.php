<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarouselSlide;
use App\Models\ContentBlock;
use App\Support\Modules\CoursePath;
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

    /**
     * @param  string  $coursePath  where the course this block was reached through is read ({@see CoursePath}), which is where the reader's permission comes from
     * @param  int|null  $number  a "Stap" block's number on its part, counted by {@see PartResource}; null for every other kind
     */
    public function __construct(ContentBlock $block, private readonly string $coursePath, private readonly ?int $number = null)
    {
        parent::__construct($block);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'position' => $this->position,

            // A "Stap" block's number (KOM-99): 1 for the first step on the part, counting only the
            // steps, so a text between two of them does not skip a number. Not stored, so moving
            // a block renumbers the rest. Null for every other kind.
            'number' => $this->number,

            // Markup: what the panel's editor produced, cleaned against the allowlist in
            // SanitizedHtml before it was stored. A client renders it as HTML. Always set on a text
            // block, and optional on a "Stap" and a checkbox block.
            'body' => $this->body,

            // A video that was linked rather than uploaded. Exactly one of this and `fileUrl` is
            // set on a video block, which is what the table's constraint says.
            'videoUrl' => $this->video_url,

            'fileUrl' => $this->file() === null ? null : $this->fileUrl(),

            // A table block's column headers and rows (KOM-101); null for every other kind.
            'table' => $this->table_content === null ? null : new ContentBlockTableResource($this->table_content),

            // A checkbox block's options, in the order they are shown (KOM-107); null for every
            // other kind. Nothing says whether a box is ticked: that is the reader's, and this
            // platform keeps no reader. A client that remembers ticks on the reader's device keys
            // them by `id`, which stays the same when an option is reworded or moved.
            /** @var list<array{id: string, label: string}>|null */
            'checkboxes' => $this->checkbox_options?->toStored(),

            // A carousel's slides, in the order a reader pages through them (KOM-100); an empty list
            // for every other kind, because the relation is simply empty there. The "1/12" beside a
            // slide is its place in this list and the list's length.
            'slides' => $this->slides
                ->map(fn (CarouselSlide $slide): ContentBlockSlideResource => new ContentBlockSlideResource(
                    $slide,
                    $this->coursePath,
                    (string) $this->step_id,
                ))
                ->values()
                ->all(),
        ];
    }

    /** Where a picture's or an uploaded video's bytes are read back from. */
    private function fileUrl(): string
    {
        return sprintf('%s/parts/%s/blocks/%s/file', $this->coursePath, $this->step_id, $this->id);
    }
}
