<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarouselSlide;
use App\Support\Modules\CoursePath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One slide of a "Carrousel" block (KOM-100): its picture, its title and its body.
 *
 * No number: the order of the list is the order a reader pages through, and the "1/12" beside a
 * slide is its place in that list and the list's length.
 *
 * @mixin CarouselSlide
 */
final class ContentBlockSlideResource extends JsonResource
{
    public static $wrap = null;

    /** @param  string  $coursePath  where the course this was reached through is read ({@see CoursePath}) */
    public function __construct(
        CarouselSlide $slide,
        private readonly string $coursePath,
        private readonly string $partId,
    ) {
        parent::__construct($slide);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,

            // Markup, cleaned against the allowlist in SanitizedHtml before it was stored, like a
            // text block's body. A client renders it as HTML. Null when the slide has none.
            'body' => $this->body,

            // Always set: a slide is not saved without its picture.
            'imageUrl' => sprintf(
                '%s/parts/%s/blocks/%s/slides/%s/image',
                $this->coursePath,
                $this->partId,
                $this->content_block_id,
                $this->id,
            ),
        ];
    }
}
