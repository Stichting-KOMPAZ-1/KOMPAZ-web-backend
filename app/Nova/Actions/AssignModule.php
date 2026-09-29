<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\SyncModuleActivationsAction;
use App\Models\Module;
use App\Nova\Concerns\RunsUseCase;
use App\Nova\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * "Actief bij" — which organizations have this module.
 *
 * A dialog rather than a field on the module form, because what it does is not writing a column.
 * An organization that already had the module keeps the date it got it, and the organization
 * administrator's table is ordered by that date; a form field would have saved a set and reset
 * every one of them. The rule lives in {@see SyncModuleActivationsAction}, so it holds for
 * anything else that ever assigns a module.
 *
 * A checkbox per organization, which is what the wireframe draws and what makes "everything
 * selected" — the state the module table reads as "Globaal" — a thing an operator can actually
 * reach in one gesture.
 */
class AssignModule extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Actief bij';

    public $confirmButtonText = 'Opslaan';

    public $cancelButtonText = 'Annuleren';

    public function __construct(private readonly SyncModuleActivationsAction $sync) {}

    /**
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $module = $this->target($models, Module::class);

        /** @var array<string, bool> $selection */
        $selection = (array) $fields->get('organizations');

        // A BooleanGroup answers with every organization and a flag, so the chosen ones are the
        // keys that came back true.
        $chosen = array_keys(array_filter($selection));

        return $this->attempt(
            fn () => $this->sync->execute($this->operator(), $module, $chosen),
            'De beschikbaarheid van deze module is bijgewerkt.',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            BooleanGroup::make('Organisaties', 'organizations')
                ->options(Organization::options())
                ->help('Vink aan welke organisaties deze module zien. Alles aangevinkt betekent Globaal.')
                // Opens on what is true now, so an operator adding one organization does not have
                // to remember and retype the others — and, more to the point, cannot take the
                // others away by not knowing they were there.
                //
                // `->default()` looks like the way to say this and is not: Nova serializes
                // `value ?? resolveDefaultValue()`, and a BooleanGroup resolves to `[]` rather
                // than null, so the default is never reached. `meta()` is merged last, so this is
                // the one seat a value can be put in from an action.
                ->withMeta(['value' => $this->currentSelection()]),
        ];
    }

    /**
     * Which organizations have it already, as the checkbox group reads that.
     *
     * @return array<string, bool>
     */
    private function currentSelection(): array
    {
        $module = $this->selected(Module::class);

        if ($module === null) {
            return [];
        }

        $active = $module->activations()->pluck('organization_id')->all();

        $selection = [];

        foreach (array_keys(Organization::options()) as $organizationId) {
            $selection[$organizationId] = in_array($organizationId, $active, true);
        }

        return $selection;
    }
}
