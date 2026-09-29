<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\ModuleStatus;
use App\Models\ModuleActivation as ModuleActivationModel;
use App\Models\User as UserModel;
use App\Support\Access\OrganizationAccess;
use App\Support\Modules\ContentRules;
use App\Support\Search\SearchPattern;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The same modules, seen from the side that has a tenant — an organization administrator's
 * "Modules".
 *
 * A resource over the activation rather than over the module, because the activation *is* the
 * organization's copy: everything they may change hangs off it, and everything they may not — the
 * name, the picture, the description, the platform's own videos — lives on the module and is shown
 * here read-only. Making that the resource means the tenant boundary is the row Nova fetches, so
 * there is no form on this page that could reach another organization's material even by mistake.
 *
 * They cannot create one: a module arrives because the platform switched it on
 * ({@see Actions\AssignModule}), which is why the create button is off rather than merely hidden.
 * They cannot delete one either — leaving would be the platform taking it away.
 */
/**
 * @extends \App\Nova\Resource<ModuleActivationModel>
 */
class ModuleActivation extends Resource
{
    use Concerns\ScopesToOperator;

    /** @var class-string<ModuleActivationModel> */
    public static $model = ModuleActivationModel::class;

    public static $title = 'id';

    /**
     * Searching here is searching the module's name, which is not a column on this table.
     *
     * Declared so Nova draws the search bar at all — the ticket asks for one — and then answered
     * by {@see self::applySearch()}, because what an organization administrator types is the name
     * of a module and the row they are looking at is their activation of it.
     *
     * @var array<int, string>
     */
    public static $search = ['id'];

    /**
     * Loaded for every row: the table shows the module's name, category and status, and a page of
     * twenty would otherwise be sixty queries.
     *
     * @var array<int, string>
     */
    public static $with = ['module.category'];

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

            Text::make('Naam', fn (): string => $this->model()->module->name)->exceptOnForms(),

            Text::make('Categorie', fn (): string => $this->model()->module->category->name)
                ->exceptOnForms(),

            // The column this table exists to draw attention to. Filling in who to ring is the one
            // thing an organization administrator must not leave undone, so it is asked of every
            // row rather than hidden inside the form.
            Boolean::make('Contactgegevens ingevuld', fn (): bool => $this->model()->hasContactDetails())
                ->exceptOnForms(),

            Badge::make('Status', fn (): string => $this->model()->module->status->value)
                ->map([
                    ModuleStatus::Available->value => 'success',
                    ModuleStatus::InDevelopment->value => 'info',
                ])
                ->labels(ModuleStatus::options())
                ->exceptOnForms(),

            Text::make('Omschrijving', fn (): string => $this->model()->module->description)
                ->onlyOnDetail(),

            // What they may actually add. Their own, on their own activation: nothing written here
            // is visible to another organization, and nothing another organization wrote is
            // visible here.
            Repeater::make("Video's", 'videos')
                ->repeatables([Repeatables\ModuleVideoRepeatable::make()])
                ->asHasMany(ModuleVideo::class)
                // Written through the use case rather than Nova's own preset, which deletes and
                // re-inserts every row by query and would lose an upload's file on each save.
                ->preset(new Repeatables\ModuleVideoPreset)
                ->rules(ContentRules::videoList()),

            Repeater::make('Extra links', 'links')
                ->repeatables([Repeatables\ModuleLinkRepeatable::make()])
                ->asHasMany(ModuleLink::class)
                ->rules(ContentRules::linkList()),

            Repeater::make('Contactpersonen', 'contacts')
                ->repeatables([Repeatables\ModuleContactRepeatable::make()])
                ->asHasMany(ModuleContact::class)
                ->rules(ContentRules::contactList()),
        ];
    }

    /**
     * Their own rows, newest first.
     *
     * Ordered by when the module was switched on for them, which the ticket asks for and which is
     * the useful order: a module they were given yesterday is the one still missing its contact
     * details.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::scopeToOperatorsOrganization($query)
            ->withCount('contacts')
            ->orderByDesc('activated_at');
    }

    /** Scoped for the same reason the listing is: a detail page is reachable by typing its URL. */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::scopeToOperatorsOrganization($query);
    }

    /**
     * Searches the module's name rather than anything on this table.
     *
     * An activation has no name of its own — the only thing an operator would ever type is the
     * name of the module, which lives one join away. Folded on both sides, like every other search
     * here, so a lower-case query matches a capitalised name whatever the server's collation says.
     *
     * Scoping is not restated: Nova applies this on top of `indexQuery`, so the rows this can
     * match are already only the operator's own.
     *
     * Typed against Nova's own non-generic contract, which is the signature it overrides.
     */
    protected static function applySearch(Builder $query, string $search): Builder
    {
        return $query->whereHas(
            'module',
            static fn (EloquentBuilder $modules): EloquentBuilder => $modules->whereRaw(
                'UPPER(name) LIKE ? ESCAPE ?',
                [SearchPattern::contains(trim($search)), SearchPattern::ESCAPE_CHARACTER],
            ),
        );
    }

    /**
     * Shown to organization administrators, and to nobody else.
     *
     * A platform administrator has {@see Module}, which is the same content from the side that
     * writes it. Offering them both would be two menu entries called "Modules" that disagree about
     * what a row is.
     */
    public static function authorizedToViewAny(Request $request): bool
    {
        $operator = Auth::user();

        return $operator instanceof UserModel
            && ! OrganizationAccess::isPlatformAdministrator($operator);
    }

    /** A module arrives because the platform switched it on, never because a tenant asked. */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /**
     * Their own copy, and nobody else's.
     *
     * The detail page is already scoped by `detailQuery`, but Nova finds the record an edit is
     * saved onto without it — so this is asked of the row itself, and it is the question rule 22
     * is about: without it, one organization's form could write another's phone numbers.
     */
    public function authorizedToView(Request $request): bool
    {
        return $this->belongsToOperatorsOrganization();
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return $this->belongsToOperatorsOrganization();
    }

    private function belongsToOperatorsOrganization(): bool
    {
        $operator = Auth::user();

        return $operator instanceof UserModel
            && ! OrganizationAccess::isPlatformAdministrator($operator)
            && $this->model()->organization_id === $operator->organization_id;
    }

    /** Leaving is the platform taking it away, which happens on the module's side. */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }
}
