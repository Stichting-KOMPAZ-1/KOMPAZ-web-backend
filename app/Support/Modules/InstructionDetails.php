<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Actions\Modules\SaveStepBlocksAction;
use Illuminate\Http\UploadedFile;

/**
 * One instruction of a "Stap" block, as a form or a request states it (KOM-99).
 *
 * The key works as every other list's here does: it names a row among *this block's* instructions
 * and nowhere else, so a key from another block is a new instruction rather than a way to rewrite
 * that one ({@see OrderedRows}).
 *
 * A picture is optional and is **kept unless it is replaced**, the way a picture block's is: an
 * edit should not have to upload every picture again. `removeImage` is how one is taken away on
 * purpose, which an optional picture needs and a picture block does not — that one cannot be
 * without.
 *
 * `imageField` and `bodyField` are where in the request each came from, or would have, so a refusal
 * about one is answered under the field the operator filled in ({@see SaveStepBlocksAction}). They
 * are not always distinct: through the panel the text lives inside the editor's JSON list, which
 * has no input of its own, so `bodyField` is the list — the nearest thing the dialog can put a
 * message under. Through the API each is its own key.
 */
final readonly class InstructionDetails
{
    public function __construct(
        public string $imageField,
        public string $bodyField,
        public ?string $title = null,
        public ?string $body = null,
        public ?UploadedFile $image = null,
        public bool $removeImage = false,
        public ?string $id = null,
    ) {}
}
