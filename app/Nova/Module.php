<?php

declare(strict_types=1);

namespace App\Nova;

use App\Actions\Modules\SyncModuleActivationsAction;
use App\Enums\ModuleStatus;
use App\Models\ELearning as ELearningModel;
use App\Models\Module as ModuleModel;
use App\Models\ModuleCategory;
use App\Models\Organization as OrganizationModel;
use App\Models\User as UserModel;
use App\Nova\Fields\CheckboxList;
use App\Support\Modules\ContentRules;
use App\Support\Modules\ModuleReach;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The modules the platform writes, and the form that writes them.
 *
 * **This is rule 18's exception, used on purpose.** Nova's own create, edit and delete are on here,
 * because none of the reasons they are off everywhere else apply: a module has no folded unique
 * column, nobody is emailed when one changes, and there is no `AdministratorCoverage` question
 * behind it. What is left is a form writing columns, which is what a form is for.
 *
 * **Actief bij** is on the form, where KOM-41 puts it, and is the one field that writes no column.
 * Which organizations have a module is a rule rather than a column — an organization that already
 * had it keeps the date it got it — so the picker writes through the same use case
 * {@see Actions\AssignModule} calls. The dialog stays as well: setting a module up is a form, and
 * changing who has it afterwards is one click from a row.
 *
 * Platform administrators only. An organization administrator reaches their own copy through
 * {@see ModuleActivation}, which is the same modules seen from the side that has a tenant.
 */
/**
 * @extends \App\Nova\Resource<ModuleModel>
 */
class Module extends Resource
{
    use Concerns\AuthoredByThePlatform;
    use Concerns\StoresUploadedImage;

    /** @var class-string<ModuleModel> */
    public static $model = ModuleModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    /**
     * What the form's organization picker is called in the request.
     *
     * Deliberately not `organizations`, which is a real relation on the model: Nova would resolve
     * the field against it and hand a collection of organizations to a field expecting a map of
     * identifiers to booleans.
     */
    private const string ACTIVE_ORGANIZATIONS = 'active_organizations';

    /** The course picker's name in the request, not `eLearnings`, for the same reason. */
    private const string E_LEARNINGS = 'linked_e_learnings';

    public static function label(): string
    {
        return 'Modules';
    }

    public static function singularLabel(): string
    {
        return 'Module';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')
                ->sortable()
                ->rules(ContentRules::moduleName()),

            // Two fields over one column, the way Status is below it. A Select renders its stored
            // value on a table rather than its label, and the stored value here is a key — so the
            // reading half is its own field and the writing half is the picker.
            Text::make('Categorie', fn (): string => $this->model()->category->name)
                ->exceptOnForms(),

            // Picked from what exists. Categories are managed on their own page (KOM-51), because
            // a name unique folded is not something a picker should be creating in passing.
            Select::make('Categorie', 'category_id')
                ->options(ModuleCategory::options())
                ->displayUsingLabels()
                ->onlyOnForms()
                ->rules(ContentRules::moduleCategory()),

            // Required on a create, as KOM-41 asks, and optional on an edit, where leaving the
            // field alone means keeping the picture already there. Not deletable for the same
            // reason. The bytes decide the media type, never the upload's own header — see the
            // store callback below.
            Image::make('Afbeelding', 'image_storage_key')
                ->disk(config('filesystems.default'))
                ->creationRules(ContentRules::requiredImage())
                ->updateRules(ContentRules::optionalImage())
                ->store($this->storesImageUnder(ModuleModel::IMAGE_PREFIX))
                // Both, and through the panel's route: Nova's default thumbnail is the disk's public
                // address, and this disk is private, so the default is a broken image.
                ->preview(fn (): ?string => $this->imageUrl())
                ->thumbnail(fn (): ?string => $this->imageUrl())
                ->prunable(false)
                ->deletable(false)
                // Not a column KOM-40 asks for.
                ->hideFromIndex(),

            Textarea::make('Omschrijving', 'description')
                ->alwaysShow()
                ->rules(ContentRules::moduleDescription()),

            // The courses this module shows. A plain link: attaching one changes nothing about the
            // course, and detaching one leaves it standing, which is what the deletion warning
            // promises an operator. A checkbox list rather than Nova's tag field, because KOM-41
            // asks for search and select all over the whole list.
            CheckboxList::make('E-learnings', self::E_LEARNINGS)
                ->options(ELearning::options())
                ->resolveUsing(fn (): array => $this->linkedCourses())
                ->fillUsing(self::syncsCourses(...))
                ->onlyOnForms(),

            // The picker is a form's: on the module's own page it would list every course on the
            // platform with a cross against most of them. What the page asks is which ones.
            Text::make('E-learnings', fn (): string => $this->courseNames())
                ->onlyOnDetail(),

            // "+" adds another entry, which is what the wireframe asks for. Videos here are the
            // platform's own; an organization's are on its activation.
            Repeater::make("Video's", 'videos')
                ->repeatables([Repeatables\ModuleVideoRepeatable::make()])
                ->asHasMany(ModuleVideo::class)
                // Written through the use case rather than Nova's own preset, which deletes and
                // re-inserts every row by query and would lose an upload's file on each save.
                ->preset(new Repeatables\ModuleVideoPreset)
                // A count is not something a row can constrain, so the form is the only place
                // that can refuse an eleventh. In the product's words, not the framework's.
                ->rules(ContentRules::videoList())
                // `asHasMany()` makes a repeater form-only, which left the module's own page — the
                // read-only view KOM-40 opens from the table — without its videos or links.
                ->showOnDetail()
                ->hideFromIndex(),

            Repeater::make('Extra links', 'links')
                ->repeatables([Repeatables\ModuleLinkRepeatable::make()])
                ->asHasMany(ModuleLink::class)
                ->rules(ContentRules::linkList())
                ->showOnDetail()
                ->hideFromIndex(),

            Textarea::make('Bronvermelding', 'source_attribution')
                ->alwaysShow()
                ->rules(ContentRules::sourceAttribution())
                ->hideFromIndex(),

            Select::make('Status', 'status')
                ->options(ModuleStatus::options())
                ->onlyOnForms()
                ->rules(ContentRules::moduleStatus()),

            // The same question on the form, which is where KOM-41 puts it: a module is written and
            // handed out in one go rather than created and then switched on somewhere else.
            //
            // Not a column and not Nova's own relation field. An activation carries a date the
            // organization administrator's table is ordered by, and an organization that already
            // had the module keeps it — so this writes through the same use case the two dialogs
            // use, and the rule has one implementation. The callback a fill returns is run *after*
            // the model is saved, which is what lets a create form hand out a module that did not
            // exist when the form was submitted.
            CheckboxList::make('Actief bij', self::ACTIVE_ORGANIZATIONS)
                ->options(Organization::options())
                ->resolveUsing(fn (): array => $this->activeOrganizations())
                ->fillUsing(self::syncsActivations(...))
                ->help('Leeg laten kan: de module wordt dan bewaard maar is nergens actief.')
                ->onlyOnForms(),

            // How far the module reaches, in the words the product chose. Computed from the counts
            // rather than stored, so a new organization takes a module back out of "Globaal" —
            // see {@see ModuleReach}.
            Text::make('Actief bij', fn (): string => ModuleReach::describe(
                (int) ($this->activations_count ?? 0),
                self::organizationCount(),
            ))->exceptOnForms(),

            // After "Actief bij", which is the order KOM-40 gives the table; the form's own order
            // is set by the two fields above.
            Badge::make('Status', 'status')
                ->map([
                    ModuleStatus::Available->value => 'success',
                    ModuleStatus::InDevelopment->value => 'info',
                ])
                ->labels(ModuleStatus::options())
                ->exceptOnForms(),
        ];
    }

    /**
     * Which organizations have this module already, as the checkbox group reads that.
     *
     * Every organization is listed, ticked or not, because the form writes the whole set back: a
     * map of only the ticked ones would be indistinguishable from a map of all of them when none
     * are.
     *
     * @return array<string, bool>
     */
    private function activeOrganizations(): array
    {
        $model = $this->model();

        $active = $model->exists
            ? $model->activations()->pluck('organization_id')->all()
            : [];

        $selection = [];

        foreach (array_keys(Organization::options()) as $organizationId) {
            $selection[$organizationId] = in_array($organizationId, $active, true);
        }

        return $selection;
    }

    /**
     * Every module, keyed by identifier, for the course form's picker. By name, as the course
     * picker on this form is.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (ModuleModel::query()->orderBy('name')->get(['id', 'name']) as $module) {
            $options[(string) $module->getKey()] = $module->name;
        }

        return $options;
    }

    /** The courses this module shows, by name, or a dash as the e-learnings table writes none. */
    private function courseNames(): string
    {
        $names = $this->model()->eLearnings()->orderBy('name')->pluck('name')->all();

        return $names === [] ? '-' : implode(', ', $names);
    }

    /**
     * Which courses this module shows already, every course listed ticked or not.
     *
     * @return array<string, bool>
     */
    private function linkedCourses(): array
    {
        $model = $this->model();

        $linked = $model->exists
            ? $model->eLearnings()->pluck('e_learnings.id')->all()
            : [];

        $selection = [];

        foreach (array_keys(ELearning::options()) as $courseId) {
            $selection[$courseId] = in_array($courseId, $linked, true);
        }

        return $selection;
    }

    /**
     * Links the ticked courses, once the module is saved and has a key to link them to.
     *
     * A plain sync, unlike the organizations: a link carries nothing of its own, so there is no
     * date to keep. Narrowed to courses that exist, so a stale key in a form left open is dropped.
     */
    private static function syncsCourses(NovaRequest $request, mixed $model): ?callable
    {
        if (! $model instanceof ModuleModel || ! $request->exists(self::E_LEARNINGS)) {
            return null;
        }

        $selection = json_decode($request->string(self::E_LEARNINGS)->toString(), true);
        $ticked = is_array($selection) ? array_keys(array_filter($selection)) : [];

        return static function () use ($model, $ticked): void {
            $model->eLearnings()->sync(
                ELearningModel::query()->whereKey($ticked)->pluck('id')->all(),
            );
        };
    }

    /**
     * Writes the picker's answer through the use case, once the module itself is saved.
     *
     * Returning a callable is what defers it: Nova collects those and invokes them after
     * `$model->save()`, so a module being created has a key by the time its activations are
     * written. Filling the rows inline would mean writing activations for a module that does not
     * exist yet.
     *
     * A form that did not carry the field at all is left alone rather than read as "none": that is
     * the difference between an operator clearing the list on purpose and some other form saving
     * without it.
     */
    private static function syncsActivations(NovaRequest $request, mixed $model): ?callable
    {
        if (! $model instanceof ModuleModel || ! $request->exists(self::ACTIVE_ORGANIZATIONS)) {
            return null;
        }

        /** @var array<string, bool> $selection */
        $selection = (array) json_decode((string) $request->input(self::ACTIVE_ORGANIZATIONS), true);

        $operator = $request->user();

        if (! $operator instanceof UserModel) {
            return null;
        }

        return static function () use ($operator, $model, $selection): void {
            app(SyncModuleActivationsAction::class)->execute(
                $operator,
                $model,
                array_keys(array_filter($selection)),
            );
        };
    }

    /** Where the panel reads this module's picture back from, when it has one. */
    private function imageUrl(): ?string
    {
        return $this->model()->image() === null
            ? null
            : route('nova.module-image', ['module' => (string) $this->model()->getKey()]);
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query
            ->with('category')
            ->withCount('activations');
    }

    /**
     * Newest first, as KOM-40 asks. The key is a UUIDv7, so it breaks ties in the same direction.
     *
     * Here rather than in `indexQuery`, because Nova adds a column the operator clicked *after*
     * whatever that query already orders by — so an order stated there wins every time, and the
     * table's sortable headers do nothing. Nova asks for this only when nothing was clicked.
     */
    public static function defaultOrderings(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The detail page shows "Actief bij" too, and reads the same count: without it here the field
     * falls back to zero and every module looks active nowhere, whatever the table says.
     */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->withCount('activations');
    }

    /** How many organizations there are, which is what turns a count into "Globaal". */
    private static function organizationCount(): int
    {
        return OrganizationModel::query()->count();
    }

    /**
     * What an operator can do here beyond the form: decide who gets it.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            // Where the pencil was, before KOM-42 asked for a table's operations in its menu.
            Actions\EditResource::for(self::class),

            app(Actions\AssignModule::class)->sole()->showInline(),

            // Nova's own row delete is off for this resource, so this is the only way to remove a
            // module — and it is the only way to show the sentence the product wrote before
            // somebody confirms something that cannot be undone.
            app(Actions\DeleteModule::class)->sole()->showInline(),
        ];
    }

    /**
     * Nova's own delete is off, which is the one place the content carve-out does not reach.
     *
     * Its confirmation modal carries a generic sentence and no resource can give it its own, and
     * the product wrote a specific one — it promises that the courses inside survive the module.
     * {@see Actions\DeleteModule} is where deleting a module lives instead.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }
}
