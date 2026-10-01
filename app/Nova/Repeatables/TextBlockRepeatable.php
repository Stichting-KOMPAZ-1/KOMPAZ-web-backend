<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\RichText;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/** A heading and a body, the body written as rich text and stored as markup. */
class TextBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Text;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van tekst', 'title')
                ->nullable()
                ->rules(ContentRules::blockTitle()),

            RichText::make('Body van tekst', 'body')
                ->rules(ContentRules::blockBody()),
        ];
    }
}
