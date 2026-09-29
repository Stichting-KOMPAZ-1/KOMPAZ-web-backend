<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;

/** A heading and a body. Plain text, by product decision, so nothing here is markup. */
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

            Textarea::make('Body van tekst', 'body')
                ->alwaysShow()
                ->rules(ContentRules::blockBody()),
        ];
    }
}
