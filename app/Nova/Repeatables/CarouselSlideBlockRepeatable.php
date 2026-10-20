<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\RichText;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Text;

/**
 * One slide of a carousel (KOM-100): a title, a body it may have, and a picture it may not.
 *
 * The same row as a step ({@see InstructionBlockRepeatable}) under its own type, and the same
 * fields — one block per slide, so other blocks can sit between two carousels. Which slides make up
 * one carousel is their being next to each other; nothing here says so, and nothing needs to.
 *
 * The picture is optional *on the field* for the reason a picture block's is: an edit that leaves
 * it alone sends nothing, and Nova validates every row of a repeater with one set of rules whether
 * the row is new or not. A slide that would end up without one is refused by the action
 * {@see ContentBlockPreset} hands the rows to, which is the only place that knows whether the row
 * already had a file.
 */
class CarouselSlideBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::CarouselSlide;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van slide', 'title')
                ->rules(ContentRules::instructionTitle()),

            RichText::make('Body van slide', 'body')
                ->nullable()
                ->rules(ContentRules::optionalBlockBody()),

            Image::make('Afbeelding', 'file_storage_key')
                ->disk(config('filesystems.default'))
                ->rules(ContentRules::optionalImage())
                ->preview(self::fileUrl(...))
                ->thumbnail(self::fileUrl(...))
                ->prunable(false)
                ->deletable(false),
        ];
    }
}
