<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\ModuleStatus;
use App\Models\Module as ModuleModel;
use App\Models\ModuleCategory;
use App\Models\Organization as OrganizationModel;
use App\Models\User as UserModel;
use App\Support\Access\OrganizationAccess;
use App\Support\Images\AcceptableLogo;
use App\Support\Modules\LimitedList;
use App\Support\Modules\ModuleMessages;
use App\Support\Modules\ModuleReach;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Tag;
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
 * The one thing that is *not* a form field is **Actief bij**. Which organizations have a module is
 * a rule rather than a column — an organization that already had it keeps the date it got it — so
 * it is {@see Actions\AssignModule} calling a use case, and this resource only shows the answer.
 *
 * Platform administrators only. An organization administrator reaches their own copy through
 * {@see ModuleActivation}, which is the same modules seen from the side that has a tenant.
 */
/**
 * @extends \App\Nova\Resource<ModuleModel>
 */
class Module extends Resource
{
    use Concerns\StoresUploadedImage;

    /** @var class-string<ModuleModel> */
    public static $model = ModuleModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

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
                ->rules(['required', 'string', 'max:'.ModuleModel::MAXIMUM_NAME_LENGTH]),

            // Two fields over one column, the way Status is below it. A Select renders its stored
            // value on a table rather than its label, and the stored value here is a key — so the
            // reading half is its own field and the writing half is the picker.
            Text::make('Categorie', fn (): string => $this->model()->category->name)
                ->exceptOnForms(),

            // Picked from what exists and never created here: adding a category is a deploy, which
            // is the honest cost of the product leaving that out of this phase.
            Select::make('Categorie', 'category_id')
                ->options(ModuleCategory::options())
                ->displayUsingLabels()
                ->onlyOnForms()
                ->rules(['required', 'uuid']),

            // Optional: some modules have no picture. The bytes decide the media type, never the
            // upload's own header — see the store callback below.
            Image::make('Afbeelding', 'image_storage_key')
                ->disk(config('filesystems.default'))
                ->rules(['nullable', new AcceptableLogo])
                ->store($this->storesImageUnder(ModuleModel::IMAGE_PREFIX))
                ->preview(fn (): ?string => $this->model()->image() === null
                    ? null
                    : route('nova.module-image', ['module' => (string) $this->model()->getKey()]))
                ->prunable(false)
                ->deletable(true)
                ->delete(self::clearsImage('image_storage_key')),

            Textarea::make('Omschrijving', 'description')
                ->alwaysShow()
                ->rules(['required', 'string']),

            Textarea::make('Bronvermelding', 'source_attribution')
                ->alwaysShow()
                ->rules(['nullable', 'string'])
                ->hideFromIndex(),

            Badge::make('Status', 'status')
                ->map([
                    ModuleStatus::Available->value => 'success',
                    ModuleStatus::InDevelopment->value => 'info',
                ])
                ->labels(ModuleStatus::options())
                ->exceptOnForms(),

            Select::make('Status', 'status')
                ->options(ModuleStatus::options())
                ->onlyOnForms()
                ->rules(['required', 'string', 'in:'.implode(',', ModuleStatus::values())]),

            // How far the module reaches, in the words the product chose. Computed from the counts
            // rather than stored, so a new organization takes a module back out of "Globaal" —
            // see {@see ModuleReach}.
            Text::make('Actief bij', fn (): string => ModuleReach::describe(
                (int) ($this->activations_count ?? 0),
                self::organizationCount(),
            ))->exceptOnForms(),

            // The courses this module shows. A plain link: attaching one changes nothing about the
            // course, and detaching one leaves it standing, which is what the deletion warning
            // promises an operator.
            Tag::make('E-learnings', 'eLearnings', ELearning::class)
                ->withPreview()
                ->hideFromIndex(),

            // "+" adds another entry, which is what the wireframe asks for. Videos here are the
            // platform's own; an organization's are on its activation.
            Repeater::make("Video's", 'videos')
                ->repeatables([Repeatables\ModuleVideoRepeatable::make()])
                ->asHasMany(ModuleVideo::class)
                // A count is not something a row can constrain, so the form is the only place
                // that can refuse an eleventh. In the product's words, not the framework's.
                ->rules(['array', new LimitedList(
                    ModuleMessages::maximumVideos(),
                    ModuleMessages::tooManyVideos(),
                )])
                ->hideFromIndex(),

            Repeater::make('Extra links', 'links')
                ->repeatables([Repeatables\ModuleLinkRepeatable::make()])
                ->asHasMany(ModuleLink::class)
                ->rules(['array', new LimitedList(
                    ModuleMessages::maximumLinks(),
                    ModuleMessages::tooManyLinks(),
                )])
                ->hideFromIndex(),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        // Newest first, as the ticket asks. The key is a UUIDv7 so it breaks ties in the same
        // direction rather than arbitrarily.
        return $query
            ->with('category')
            ->withCount('activations')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
            app(Actions\AssignModule::class)->sole()->showInline(),

            // Nova's own row delete is off for this resource, so this is the only way to remove a
            // module — and it is the only way to show the sentence the product wrote before
            // somebody confirms something that cannot be undone.
            app(Actions\DeleteModule::class)->sole()->showInline(),
        ];
    }

    /** Authoring a module is the platform's job, never a tenant's. */
    public static function authorizedToViewAny(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
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

    private static function operatorIsPlatformAdministrator(): bool
    {
        $operator = Auth::user();

        return $operator instanceof UserModel && OrganizationAccess::isPlatformAdministrator($operator);
    }
}
