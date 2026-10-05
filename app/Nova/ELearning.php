<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\ELearning as ELearningModel;
use App\Models\Module as ModuleModel;
use App\Nova\Breadcrumbs\NestedResource;
use App\Nova\Fields\CheckboxList;
use App\Support\Modules\ContentRules;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The courses, as an operator writes them.
 *
 * A course is independent of any module: one can be shown by several, by one, or by none. The link
 * can be made from either form — the module's "E-learnings" or this one's "Modules" — because both
 * write the same pivot. This resource owns the course itself — its name and its
 * picture — and the table KOM-44 asks for, which reports how large it is and which modules show it.
 *
 * Platform administrators only. A course carries no tenancy of its own — it is reached through the
 * modules that show it — so there is no scoped version of this listing to give anybody else, and
 * the ticket says so outright.
 *
 * Deleting is {@see Actions\DeleteELearning} rather than Nova's own, for the reason a module's is:
 * the product wrote the confirmation, and it promises that the modules linking to the course
 * survive it.
 *
 * Chapters are written from the course's page and steps from a chapter's, which is the wireframe's
 * path: {@see Chapter}, then {@see Step}. Neither is in the menu.
 */
/**
 * @extends \App\Nova\Resource<ELearningModel>
 */
class ELearning extends Resource implements NestedResource
{
    use Concerns\AuthoredByThePlatform;
    use Concerns\StoresUploadedImage;

    /** @var class-string<ELearningModel> */
    public static $model = ELearningModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    /** How many characters of "In module(s)" the table shows before cutting the list off. */
    private const int MODULE_NAMES_IN_TABLE = 40;

    /**
     * The module picker's name in the request, not `modules`, which is a real relation on the
     * model: Nova would resolve the field against it and hand a collection of modules to a field
     * expecting a map of identifiers to booleans.
     */
    private const string MODULES = 'linked_modules';

    public static function label(): string
    {
        return 'E-learnings';
    }

    public static function singularLabel(): string
    {
        return 'E-learning';
    }

    /**
     * The top of the path: a course sits under the menu and nothing else.
     *
     * @return \App\Nova\Resource<covariant \Illuminate\Database\Eloquent\Model>|null
     */
    public function parentResource(): ?Resource
    {
        return null;
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')
                ->sortable()
                ->rules(ContentRules::eLearningName()),

            // Required, unlike a module's: the columns behind it are not nullable, because a course
            // is what somebody works through and it has no placeholder to fall back to. Optional on
            // an edit, where leaving the field alone means keeping the picture already there.
            Image::make('Afbeelding', 'image_storage_key')
                ->disk(config('filesystems.default'))
                ->creationRules(ContentRules::requiredImage())
                ->updateRules(ContentRules::optionalImage())
                ->store($this->storesImageUnder(ELearningModel::IMAGE_PREFIX))
                // Null on a create form: Nova builds the fields against a record that has no key
                // yet, and an address for a course that does not exist is not a missing thumbnail
                // but a 500 on the form itself.
                ->preview(fn (): ?string => $this->imageUrl())
                // Nova's default thumbnail is the disk's public address, and the disk is private.
                ->thumbnail(fn (): ?string => $this->imageUrl())
                ->prunable(false)
                ->deletable(false)
                // Not a column KOM-44 or KOM-56 asks for.
                ->hideFromIndex(),

            // The same link the module form's "E-learnings" writes, from the other end: it is one
            // pivot, so a course linked here is ticked there and the other way round. Linking
            // changes nothing about either side and deletes nothing when undone.
            //
            // The same picker as that one, too. Nova's tag field offered nothing until somebody
            // typed, dropped a pick made while a search was still loading, and searched what was
            // saved rather than what was on the form — so a module removed from the list could not
            // be found again until the course was saved without it.
            CheckboxList::make('Modules', self::MODULES)
                ->options(Module::options())
                ->resolveUsing(fn (): array => $this->linkedModules())
                ->fillUsing(self::syncsModules(...))
                ->onlyOnForms(),

            // The two numbers the listing is for: how big the course is, and whether anybody shows
            // it. Counted by the database rather than by loading the rows.
            Number::make('Hoofdstukken', fn (): int => (int) ($this->chapters_count ?? 0))
                ->exceptOnForms(),

            // Cut off in the table, as KOM-56 asks, and whole on the course's own page.
            Text::make('In module(s)', fn (): string => $request->isResourceIndexRequest()
                ? Str::limit($this->moduleNames(), self::MODULE_NAMES_IN_TABLE, '…')
                : $this->moduleNames())
                ->exceptOnForms(),

            // The course's page is where its chapters are written: the table, and the button that
            // creates one already attached to this course.
            HasMany::make('Hoofdstukken', 'chapters', Chapter::class),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query
            ->withCount('chapters')
            ->with('modules:id,name');
    }

    /**
     * Newest first, as KOM-44 asks. The key is a UUIDv7, so it breaks ties in the same direction.
     *
     * Here rather than in `indexQuery`, because Nova adds a column the operator clicked *after*
     * whatever that query already orders by — so an order stated there wins every time, and the
     * table's sortable headers do nothing. Nova asks for this only when nothing was clicked.
     */
    public static function defaultOrderings(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** The same two numbers on the course's own page, which would otherwise read as zero and a dash. */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query
            ->withCount('chapters')
            ->with('modules:id,name');
    }

    /**
     * Every course, keyed by identifier, for the module form's picker. By name, so a long list
     * reads the way somebody looks for one.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (ELearningModel::query()->orderBy('name')->get(['id', 'name']) as $course) {
            $options[(string) $course->getKey()] = $course->name;
        }

        return $options;
    }

    /**
     * Where the panel reads this course's picture back from, once there is one to read.
     *
     * Only the missing key is checked. A course that exists always has a picture — the columns are
     * not nullable, unlike a module's — so there is no second case where the address would be
     * wrong.
     */
    private function imageUrl(): ?string
    {
        $key = $this->model()->getKey();

        if (! is_string($key) || $key === '') {
            return null;
        }

        return route('nova.e-learning-image', ['eLearning' => $key]);
    }

    /**
     * The modules this course appears in, or a dash when it appears in none.
     *
     * A dash rather than an empty cell, because "nobody shows this yet" is a fact worth reading and
     * a blank looks like a column that failed to load.
     */
    private function moduleNames(): string
    {
        $names = $this->model()->modules->pluck('name')->all();

        return $names === [] ? '-' : implode(', ', $names);
    }

    /**
     * Which modules show this course already, every module listed ticked or not.
     *
     * @return array<string, bool>
     */
    private function linkedModules(): array
    {
        $model = $this->model();

        $linked = $model->exists
            ? $model->modules()->pluck('modules.id')->all()
            : [];

        $selection = [];

        foreach (array_keys(Module::options()) as $moduleId) {
            $selection[$moduleId] = in_array($moduleId, $linked, true);
        }

        return $selection;
    }

    /**
     * Links the ticked modules, once the course is saved and has a key to link them to.
     *
     * The module form's course picker from the other end, and for the same reasons a plain sync
     * narrowed to modules that exist. A form that did not carry the field is left alone rather
     * than read as "none".
     */
    private static function syncsModules(NovaRequest $request, mixed $model): ?callable
    {
        if (! $model instanceof ELearningModel || ! $request->exists(self::MODULES)) {
            return null;
        }

        $selection = json_decode($request->string(self::MODULES)->toString(), true);
        $ticked = is_array($selection) ? array_keys(array_filter($selection)) : [];

        return static function () use ($model, $ticked): void {
            $model->modules()->sync(
                ModuleModel::query()->whereKey($ticked)->pluck('id')->all(),
            );
        };
    }

    /**
     * What an operator can do here beyond the form.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            Actions\EditResource::for(self::class),

            app(Actions\DeleteELearning::class)->sole(),
        ];
    }

    /** Off for the reason a module's is: the deletion warning is the product's, not Nova's. */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }
}
