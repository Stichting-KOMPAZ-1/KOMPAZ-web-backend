<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Enums\ContentBlockType;
use Illuminate\Http\UploadedFile;

/**
 * One block of a step, as a form or a request states it.
 *
 * Only the fields its kind uses are read: a text block's `videoUrl` is ignored rather than refused,
 * and the table's constraints are what hold if something gets past that.
 *
 * The key works as {@see LinkDetails}'s does, among the step's own blocks, and a key that names a
 * block of another kind is a new block: a picture does not turn into a text by being re-sent.
 *
 * `imageField` is where in the request the picture came from, or would have. A new picture block
 * without one is refused under that name — the panel's and the API's are different paths to the
 * same mistake. The two video fields are the same idea for a video block's link and its upload;
 * see {@see VideoDetails}. `tableField` is the same again for a table block's table, and
 * `checkboxesField` for a checkbox block's options, and `titleField` for the title a "Stap" block
 * cannot be without.
 *
 * A carousel's content is rows rather than columns, so its slides arrive as a list of
 * {@see SlideDetails}, and `slidesField` is where a refusal about the list as a whole goes.
 *
 * `removeImage` takes a picture away from the one kind whose picture is optional, a "Stap" block:
 * leaving `image` out keeps the picture it has, as it does for a picture block.
 */
final readonly class BlockDetails
{
    public function __construct(
        public ContentBlockType $type,
        public string $imageField,
        public string $videoUrlField,
        public string $videoUploadField,
        public string $tableField,
        public string $checkboxesField,
        public string $titleField,
        public string $slidesField,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $videoUrl = null,
        public ?string $videoUploadId = null,
        public ?UploadedFile $image = null,
        public ?BlockTable $table = null,
        public ?BlockCheckboxes $checkboxes = null,
        public bool $removeImage = false,
        /** @var list<SlideDetails> */
        public array $slides = [],
        public ?string $id = null,
    ) {}
}
