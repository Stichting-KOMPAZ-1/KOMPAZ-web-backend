<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use Illuminate\Http\UploadedFile;

/**
 * One slide of a "Carrousel" block, as a form or a request states it (KOM-100).
 *
 * The key works as every other list's here does: it names a row among *this block's* slides and
 * nowhere else, so a key from another carousel is a new slide rather than a way to rewrite that one
 * ({@see OrderedRows}). A picture is **kept unless it is replaced**, as a picture block's is, so an
 * edit need not upload every picture again; a new slide without one is refused.
 *
 * `titleField` and `imageField` are where a refusal about each is answered
 * ({@see SaveStepBlocksAction}). Through the API each is its own key. Through the panel the slides
 * are one editor whose list has no input per value, so both are the editor — the nearest thing the
 * dialog can put a message under — and the message says which slide it means.
 */
final readonly class SlideDetails
{
    public function __construct(
        public string $titleField,
        public string $imageField,
        public ?string $title = null,
        public ?string $body = null,
        public ?UploadedFile $image = null,
        public ?string $id = null,
    ) {}
}
