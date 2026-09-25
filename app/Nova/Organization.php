<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\Organization as OrganizationModel;
use App\Models\User as UserModel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The tenants, as an operator sees them.
 *
 * The fields are read-only and Nova's own forms stay off, for the same reason {@see User}'s do:
 * renaming an organization has a uniqueness rule that folds case, and deleting one takes its
 * people and its logo with it through paths that raise the notices those people are owed. None of
 * that belongs in a Nova form. Everything an operator can do here is one of the actions in
 * {@see Actions}, each of which calls the same use case the API calls.
 */
/**
 * @extends \App\Nova\Resource<OrganizationModel>
 */
class Organization extends Resource
{
    use Concerns\ScopesToOperator;

    /** @var class-string<OrganizationModel> */
    public static $model = OrganizationModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static function label(): string
    {
        return 'Organisaties';
    }

    public static function singularLabel(): string
    {
        return 'Organisatie';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')->sortable()->readonly(),

            Boolean::make('Platform', 'is_platform')->sortable()->readonly(),

            // The image the organization is shown with, or the placeholder that stands in for it —
            // read back through the panel's own route, since an <img> cannot carry a bearer token.
            // Shown on the list as well, because "this one has no logo yet" is something an
            // operator should be able to see without opening every row; the list asks for it at
            // thumbnail size, and the detail at a size worth looking at.
            $this->logo('Logo', '2rem', '6rem')->onlyOnIndex(),

            $this->logo('Logo', '6rem', '16rem')->onlyOnDetail(),

            // Shown on the index as well as the detail: whether an organization is out of service
            // is the one thing about it an operator needs to see without opening it.
            Boolean::make('Gearchiveerd', fn (): bool => $this->model()->isArchived())
                ->exceptOnForms(),

            DateTime::make('Gearchiveerd op', 'archived_at')->onlyOnDetail(),

            Number::make('Gebruikers', fn (): int => $this->model()->users()->whereNull('deleted_at')->count())
                ->exceptOnForms(),

            DateTime::make('Aangemaakt op', 'created_at')->sortable()->onlyOnDetail(),
            DateTime::make('Gewijzigd op', 'updated_at')->onlyOnDetail(),

            HasMany::make('Gebruikers', 'users', User::class),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::scopeToOwnOrganization($query)->orderBy('name');
    }

    /**
     * The organizations an operator may see: every one for a platform administrator, and their own
     * for anybody else. Keyed on the row's own identifier rather than an `organization_id` column,
     * which is the one place the tenant boundary is spelled differently.
     */
    private static function scopeToOwnOrganization(Builder $query): Builder
    {
        $operator = Auth::user();

        if (! $operator instanceof UserModel) {
            return $query->whereRaw('1 = 0');
        }

        if (self::operatorSeesEveryTenant()) {
            return $query;
        }

        return $query->whereKey($operator->organization_id);
    }

    /** Scoped for the same reason the listing is: a detail page is reachable by its key. */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::scopeToOwnOrganization($query);
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    /**
     * Refused so that Nova's own create, edit and delete stay off.
     *
     * Not a statement that an operator may not do these things — they may, through the actions
     * below. It is a statement that Nova's forms write columns straight to the database, which
     * would go around the uniqueness fold, the logo's discard event and the notices a deletion
     * owes.
     */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /**
     * What an operator can do here: the API's write endpoints for an organization, each delegating
     * to the same use case.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        // Creating, archiving and deleting a tenant are the platform's own operations: the use
        // cases behind them all require a platform administrator and would refuse an organization
        // administrator with a banner. Hidden rather than left to refuse, for the reason the
        // platform organization's own buttons are hidden — a button that cannot work is worse than
        // no button. Renaming and the logo stay, because the API lets an administrator do both for
        // their own organization.
        $platformOnly = static fn (): bool => self::operatorSeesEveryTenant();

        return [
            app(Actions\CreateOrganization::class)->standalone()->canSee($platformOnly),

            app(Actions\UpdateOrganization::class)->sole()
                ->showInline(),

            app(Actions\UploadOrganizationLogo::class)->sole()
                ->showInline(),

            app(Actions\DeleteOrganizationLogo::class)
                ->sole()
                ->showInline()
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => $organization->logo !== null),

            // The platform's own organization is refused by the use case as well. Hidden here too,
            // because offering a button that cannot work is worse than not offering it.
            app(Actions\ArchiveOrganization::class)
                ->sole()
                ->showInline()
                ->canSee($platformOnly)
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => ! $organization->is_platform
                    && ! $organization->isArchived()),

            app(Actions\UnarchiveOrganization::class)
                ->sole()
                ->showInline()
                ->canSee($platformOnly)
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => $organization->isArchived()),

            app(Actions\DeleteOrganization::class)
                ->sole()
                ->showInline()
                ->canSee($platformOnly)
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => ! $organization->is_platform),
        ];
    }

    /**
     * The organization's image at a given size, as the panel's own route serves it.
     *
     * The bytes are the same either way — an organization that uploaded nothing is answered with
     * the placeholder, so there is no "no logo" case for this to render — and only the box they
     * are drawn in differs between the list and the detail.
     */
    private function logo(string $label, string $maximumHeight, string $maximumWidth): Text
    {
        return Text::make($label, fn (): string => sprintf(
            '<img src="%s" alt="%s" style="max-height: %s; max-width: %s">',
            e(route('nova.organization-logo', ['organization' => (string) $this->model()->getKey()])),
            e($this->model()->name),
            e($maximumHeight),
            e($maximumWidth),
        ))->asHtml();
    }

    /**
     * Every organization, keyed by identifier, for the pickers the user operations show.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $operator = Auth::user();

        if (! $operator instanceof UserModel) {
            return [];
        }

        $query = OrganizationModel::query();

        // Scoped here rather than through the query hooks above, which Nova types against its own
        // non-generic contract: this one stays an Eloquent query so that what comes back is a
        // collection of organizations rather than of models in general.
        if (! self::operatorSeesEveryTenant()) {
            $query->whereKey($operator->organization_id);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (OrganizationModel $organization): array => [
                (string) $organization->getKey() => $organization->name,
            ])
            ->all();
    }
}
