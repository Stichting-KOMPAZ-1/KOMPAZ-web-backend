<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlockInstruction;
use App\Support\Modules\CoursePath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One instruction of a "Stap" block (KOM-99): its heading, its text, and its picture.
 *
 * No number: the order of the list is the numbering, so sending one as well would be a second
 * answer to the same question that a reordering could contradict.
 *
 * @mixin BlockInstruction
 */
final class ContentBlockInstructionResource extends JsonResource
{
    public static $wrap = null;

    /** @param  string  $coursePath  where the course this was reached through is read ({@see CoursePath}) */
    public function __construct(
        BlockInstruction $instruction,
        private readonly string $coursePath,
        private readonly string $partId,
    ) {
        parent::__construct($instruction);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,

            // Markup, cleaned against the allowlist in SanitizedHtml before it was stored, like a
            // text block's body. A client renders it as HTML.
            'body' => $this->body,

            'imageUrl' => $this->file() === null ? null : $this->imageUrl(),
        ];
    }

    /** Where this instruction's picture is read back from, through its block and its part. */
    private function imageUrl(): string
    {
        return sprintf(
            '%s/parts/%s/blocks/%s/instructions/%s/image',
            $this->coursePath,
            $this->partId,
            $this->content_block_id,
            $this->id,
        );
    }
}
