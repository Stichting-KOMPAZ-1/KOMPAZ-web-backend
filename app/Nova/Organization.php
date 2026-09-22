<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\Organization as OrganizationModel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
            // On the detail only: the roster is a list of names, and a logo may be ten megabytes.
            Text::make('Logo', fn (): string => sprintf(
                '<img src="%s" alt="%s" style="max-height: 6rem; max-width: 16rem">',
                e(route('nova.organization-logo', ['organization' => (string) $this->model()->getKey()])),
                e($this->model()->name),
            ))->asHtml()->onlyOnDetail(),

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
        return $query->orderBy('name');
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
        return [
            app(Actions\CreateOrganization::class)->standalone(),

            app(Actions\UpdateOrganization::class)->sole(),

            app(Actions\UploadOrganizationLogo::class)->sole(),

            app(Actions\DeleteOrganizationLogo::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => $organization->logo !== null),

            // The platform's own organization is refused by the use case as well. Hidden here too,
            // because offering a button that cannot work is worse than not offering it.
            app(Actions\ArchiveOrganization::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => ! $organization->is_platform
                    && ! $organization->isArchived()),

            app(Actions\UnarchiveOrganization::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => $organization->isArchived()),

            app(Actions\DeleteOrganization::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, OrganizationModel $organization): bool => ! $organization->is_platform),
        ];
    }

    /**
     * Every organization, keyed by identifier, for the pickers the user operations show.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return OrganizationModel::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (OrganizationModel $organization): array => [
                (string) $organization->getKey() => $organization->name,
            ])
            ->all();
    }
}
