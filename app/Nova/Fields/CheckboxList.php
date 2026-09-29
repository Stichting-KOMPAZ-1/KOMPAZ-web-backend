<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Providers\NovaServiceProvider;
use Laravel\Nova\Fields\BooleanGroup;

/**
 * Nova's boolean group, with a search box and "select all / none" above the checkboxes.
 *
 * KOM-41 asks for the module form's two pickers to be a table with search, select all and
 * deselect all, and Nova has no such field. This one is a BooleanGroup to the server — same
 * options, same value, same JSON on the way back — so a field built on it keeps its `resolveUsing`
 * and `fillUsing` unchanged; only the component that draws it differs.
 *
 * The component lives in `resources/nova/checkbox-list`, is built there and committed as
 * `dist/js/field.js`, and is registered in {@see NovaServiceProvider}.
 */
class CheckboxList extends BooleanGroup
{
    /** @var string */
    public $component = 'checkbox-list';

    /** The component's own words, in Dutch like everything else an operator reads. */
    #[\Override]
    protected function configureDefaults(): void
    {
        parent::configureDefaults();

        $this->withMeta([
            'searchPlaceholder' => 'Zoeken',
            'selectAllLabel' => 'Alles selecteren',
            'deselectAllLabel' => 'Niets selecteren',
            'emptyLabel' => 'Niets gevonden.',
        ]);
    }
}
