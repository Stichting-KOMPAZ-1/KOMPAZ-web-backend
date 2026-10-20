<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\ContentBlock;
use App\Nova\Repeatables\ContentBlockPreset;
use App\Support\Modules\BlockTable;
use Laravel\Nova\Fields\Field;

/**
 * The table of a "Tabel" block, written in the panel (KOM-101): column headers, "+ kolom" up to
 * ten, and rows with one text field per column, "+ rij" to add one and a cross to take it away.
 *
 * Nova has no field for a grid whose width the operator chooses, and its repeater cannot nest one
 * inside a block's row, so the component is this application's own, in `resources/nova/panel`. It
 * sends the table as JSON; an untouched one sends nothing, so a "Tabel" added with "+ blok" and
 * then left alone is dropped as any empty row is (KOM-73) rather than refused.
 *
 * The field writes no column itself. It lives in a block's row, and {@see ContentBlockPreset}
 * reads it out of the request for the use case that saves every block — which is why its own fill
 * does nothing.
 */
final class TableEditor extends Field
{
    /** @var string */
    public $component = 'table-editor';

    public function __construct(string $name, string $attribute)
    {
        parent::__construct($name, $attribute);

        $this->fillUsing(static fn (): null => null);

        // A table needs room: the label goes above it rather than beside it, and the grid takes
        // the form's whole width instead of the three fifths a field gets (KOM-101).
        $this->stacked()->fullWidth();

        $this->withMeta([
            'maximumColumns' => BlockTable::MAXIMUM_COLUMNS,
            'maximumRows' => BlockTable::MAXIMUM_ROWS,
            'columnsLabel' => 'Kolomkoppen',
            'rowsLabel' => 'Rijen',
            'headerPlaceholder' => 'Kolomkop',
            'addColumnLabel' => '+ kolom',
            'columnsCountLabel' => 'kolommen',
            'removeColumnLabel' => 'Kolom verwijderen',
            'addRowLabel' => '+ rij',
            'removeRowLabel' => 'Rij verwijderen',
            'emptyLabel' => 'Geen rijen',
        ]);
    }

    /**
     * The block's table as the component edits it, or null for a block that has none yet.
     *
     * @param  mixed  $resource
     * @return array{columns: list<string>, rows: list<list<string>>}|null
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): ?array
    {
        $table = $resource instanceof ContentBlock ? $resource->table_content : null;

        return $table?->toArray();
    }
}
