<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\SlideListEditor;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;

/**
 * A carousel (KOM-100): one block holding its slides, which a reader pages through in one place.
 *
 * The slides are not plain fields, because Nova's repeater cannot nest a repeating list inside
 * this row ({@see SlideListEditor}). A carousel has no title of its own; each slide has one.
 */
class CarouselBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Carousel;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            SlideListEditor::make('Slides', 'slides')
                ->stacked()
                ->fullWidth()
                ->rules(ContentRules::slideList()),
        ];
    }
}
