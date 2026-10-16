<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Enums\ContentBlockType;
use App\Nova\Fields\TableEditor;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;

/**
 * An optional title and a table of plain text (KOM-101): one to ten columns, each with a header,
 * and rows that need not be filled in completely.
 */
class TableBlockRepeatable extends ContentBlockRepeatable
{
    public static function type(): ContentBlockType
    {
        return ContentBlockType::Table;
    }

    /** @return array<int, Field> */
    protected function contentFields(): array
    {
        return [
            Text::make('Titel van tabel', 'title')
                ->nullable()
                ->rules(ContentRules::blockTitle()),

            TableEditor::make('Tabel', 'table_content')
                ->rules(ContentRules::blockTable()),
        ];
    }
}
