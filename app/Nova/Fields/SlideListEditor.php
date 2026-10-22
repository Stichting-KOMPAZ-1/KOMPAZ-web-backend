<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\CarouselSlide;
use App\Models\ContentBlock;
use App\Nova\Repeatables\ContentBlockPreset;
use Laravel\Nova\Fields\Field;

/**
 * The slides of a "Carrousel" block, written in the panel (KOM-100): a picture, a title and a body
 * per slide, "+ slide" to add one, arrows to move one, and a cross to take it away.
 *
 * Nova's repeater cannot nest a repeating list inside a block's row, which is why the table and the
 * checkbox options have components of their own; this is the largest of them, because a slide also
 * carries a file. The list travels as JSON and each picture beside it as a file under a key of its
 * own, {@see pictureKey()}: the component is handed its prefix and {@see ContentBlockPreset} reads
 * it, so the two cannot disagree.
 *
 * The field writes no column itself. It lives in a block's row, and the preset reads it out of the
 * request for the use case that saves every block — which is why its own fill does nothing.
 */
final class SlideListEditor extends Field
{
    /** @var string */
    public $component = 'slide-list-editor';

    public function __construct(string $name, string $attribute)
    {
        parent::__construct($name, $attribute);

        $this->fillUsing(static fn (): null => null);

        $this->withMeta([
            'maximumSlides' => CarouselSlide::MAXIMUM_PER_CAROUSEL,
            'maximumTitleLength' => CarouselSlide::MAXIMUM_TITLE_LENGTH,
            'slideLabel' => 'Slide',
            'imageLabel' => 'Afbeelding',
            'titlePlaceholder' => 'Titel van slide',
            'bodyPlaceholder' => 'Body van slide (optioneel)',
            'addSlideLabel' => '+ slide',
            'removeSlideLabel' => 'Slide verwijderen',
            'moveUpLabel' => 'Slide omhoog',
            'moveDownLabel' => 'Slide omlaag',
            'emptyLabel' => 'Geen slides',
            'picturePrefix' => self::picturePrefix($attribute),
        ]);
    }

    /**
     * The key one slide's picture is sent under, beside the list.
     *
     * No dot and no bracket: Nova's repeater sends each of a row's keys as
     * `blocks[0][fields][<key>]`, so a key of `slides_images.0` arrived as one literal key with a
     * dot in it, which Laravel's dotted paths read as a nested one — and every picture chosen in the
     * panel was silently dropped.
     */
    public static function pictureKey(string $attribute, int $position): string
    {
        return self::picturePrefix($attribute).$position;
    }

    private static function picturePrefix(string $attribute): string
    {
        return "{$attribute}_picture_";
    }

    /**
     * The block's slides as the component edits them, with each picture as the address it is read
     * back from rather than its bytes.
     *
     * @param  mixed  $resource
     * @return list<array{id: string, title: string, body: ?string, imageUrl: string}>|null
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): ?array
    {
        if (! $resource instanceof ContentBlock) {
            return null;
        }

        return $resource->slides
            ->map(static fn (CarouselSlide $slide): array => [
                'id' => (string) $slide->getKey(),
                'title' => $slide->title,
                'body' => $slide->body,
                'imageUrl' => route('nova.carousel-slide-image', ['slide' => $slide->getKey()]),
            ])
            ->values()
            ->all();
    }
}
