<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\SyncModuleActivationsAction;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
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
 * Which modules an organization has, asked from the organization's side.
 *
 * The same rows as {@see AssignModule} and deliberately so. An operator setting up a new
 * organization is holding the organization and wants to hand it a set of modules; an operator who
 * has just written a module is holding the module and wants to say who gets it. Offering only the
 * second means the first has to open every module in turn and tick one box on each, which is the
 * kind of thing that gets half done.
 *
 * Both call the same use case, so the rule that matters — a module the organization already had
 * keeps the date it got it — has one implementation rather than two.
 */
class AssignModulesToOrganization extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Modules';

    public $confirmButtonText = 'Opslaan';

    public $cancelButtonText = 'Annuleren';

    public function __construct(private readonly SyncModuleActivationsAction $sync) {}

    /**
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        /** @var array<string, bool> $selection */
        $selection = (array) $fields->get('modules');

        return $this->attempt(
            fn () => $this->sync->executeForOrganization(
                $this->operator(),
                $organization,
                array_keys(array_filter($selection)),
            ),
            'De modules van deze organisatie zijn bijgewerkt.',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            BooleanGroup::make('Modules', 'modules')
                ->options(self::moduleOptions())
                ->help('Vink aan welke modules deze organisatie ziet.')
                // Opens on what is true now. Put in `meta` rather than through `->default()`,
                // which Nova never reaches for a BooleanGroup — see {@see AssignModule}.
                ->withMeta(['value' => $this->currentSelection()]),
        ];
    }

    /**
     * Every module, keyed by identifier, newest first — the order the modules table uses.
     *
     * @return array<string, string>
     */
    private static function moduleOptions(): array
    {
        return Module::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Module $module): array => [
                (string) $module->getKey() => $module->name,
            ])
            ->all();
    }

    /**
     * Which modules this organization has already, as the checkbox group reads that.
     *
     * @return array<string, bool>
     */
    private function currentSelection(): array
    {
        $organization = $this->selected(Organization::class);

        if ($organization === null) {
            return [];
        }

        $active = ModuleActivation::query()
            ->where('organization_id', $organization->getKey())
            ->pluck('module_id')
            ->all();

        $selection = [];

        foreach (array_keys(self::moduleOptions()) as $moduleId) {
            $selection[$moduleId] = in_array($moduleId, $active, true);
        }

        return $selection;
    }
}
