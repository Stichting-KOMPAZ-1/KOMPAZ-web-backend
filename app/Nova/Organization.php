<?php

declare(strict_types=1);

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
 * Read-only for the same reason {@see User} is: renaming an organization has a uniqueness rule
 * that folds case, and deleting one takes its people and its logo with it through paths that raise
 * the notices those people are owed. Both live in actions the API calls.
 */
/**
 * @extends \App\Nova\Resource<\App\Models\Organization>
 */
class Organization extends Resource
{
    /** @var class-string<\App\Models\Organization> */
    public static $model = \App\Models\Organization::class;

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

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }
}
