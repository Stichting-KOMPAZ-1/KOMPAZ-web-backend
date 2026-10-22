<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Models\ContentBlock;
use App\Nova\Repeatables\ContentBlockPreset;
use App\Support\Modules\BlockCheckboxes;
use Laravel\Nova\Fields\Field;

/**
 * The options of a "Checkboxes" block, written in the panel (KOM-107): one text field per option,
 * "+ optie" up to twenty-five, and a cross to take one away.
 *
 * Nova's repeater cannot nest a repeating list inside a block's row, which is the same reason the
 * table has a component of its own, so this is one too — the simpler, one-dimensional half of
 * {@see TableEditor}. It sends the options as JSON, each with the identifier it was read with, so
 * rewording or moving an option keeps it the same option; an untouched block sends nothing, so a
 * "Checkboxes" added with "+ blok" and then left alone is dropped as any empty row is (KOM-73)
 * rather than refused.
 *
 * The field writes no column itself. It lives in a block's row, and {@see ContentBlockPreset}
 * reads it out of the request for the use case that saves every block — which is why its own fill
 * does nothing.
 */
final class CheckboxListEditor extends Field
{
    /** @var string */
    public $component = 'checkbox-list-editor';

    public function __construct(string $name, string $attribute)
    {
        parent::__construct($name, $attribute);

        $this->fillUsing(static fn (): null => null);

        $this->withMeta([
            'maximumOptions' => BlockCheckboxes::MAXIMUM_OPTIONS,
            'maximumLength' => BlockCheckboxes::MAXIMUM_LABEL_LENGTH,
            'optionPlaceholder' => 'Optie',
            'addOptionLabel' => '+ optie',
            'removeOptionLabel' => 'Optie verwijderen',
            'emptyLabel' => 'Geen opties',
        ]);
    }

    /**
     * The block's options as the component edits them, or null for a block that has none yet.
     *
     * @param  mixed  $resource
     * @return list<array{id: string, label: string}>|null
     */
    #[\Override]
    protected function resolveAttribute($resource, string $attribute): ?array
    {
        $options = $resource instanceof ContentBlock ? $resource->checkbox_options : null;

        return $options?->toStored();
    }
}
