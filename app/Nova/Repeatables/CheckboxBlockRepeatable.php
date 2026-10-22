<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\CheckboxListEditor;
use App\Nova\Fields\RichText;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/**
 * An optional title, an optional body and a list of options the reader ticks off (KOM-107): at
 * least one and at most twenty-five, each a line of plain text.
 */
class CheckboxBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Checkboxes;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van checkboxes', 'title')
                ->nullable()
                ->rules(ContentRules::blockTitle()),

            RichText::make('Body van checkboxes', 'body')
                ->nullable()
                ->rules(ContentRules::optionalBlockBody()),

            CheckboxListEditor::make('Opties', 'checkbox_options')
                ->rules(ContentRules::blockCheckboxes()),
        ];
    }
}
