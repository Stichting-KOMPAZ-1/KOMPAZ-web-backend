<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\CheckboxListEditor;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/**
 * An optional title and a list of options the reader ticks off (KOM-107): at most twenty, each a
 * line of plain text.
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

            CheckboxListEditor::make('Opties', 'checkbox_options')
                ->rules(ContentRules::blockCheckboxes()),
        ];
    }
}
