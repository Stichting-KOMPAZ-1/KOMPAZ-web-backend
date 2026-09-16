<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The people in the system, as an operator sees them.
 *
 * Read-mostly on purpose. Inviting, editing and deleting somebody all carry rules that the API's
 * actions enforce — who may grant which role, whether an administrator would be left, whether a
 * deletion notice is truthful — and a Nova form that wrote these columns directly would bypass
 * every one of them. Support work is done here; changing who somebody is is done through the API.
 */
/**
 * @extends \App\Nova\Resource<\App\Models\User>
 */
class User extends Resource
{
    /** @var class-string<\App\Models\User> */
    public static $model = \App\Models\User::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name', 'email'];

    public static function label(): string
    {
        return 'Gebruikers';
    }

    public static function singularLabel(): string
    {
        return 'Gebruiker';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->asBigInt()->onlyOnDetail(),

            Text::make('Naam', 'name')->sortable()->readonly(),

            Text::make('E-mailadres', 'email')->sortable()->readonly(),

            BelongsTo::make('Organisatie', 'organization', Organization::class)->sortable()->readonly(),

            Select::make('Rol', 'role')
                ->options(array_combine(UserRole::values(), UserRole::values()))
                ->sortable()
                ->readonly(),

            Badge::make('Status', 'status')
                ->map([
                    UserStatus::Invited->value => 'warning',
                    UserStatus::Active->value => 'success',
                ])
                ->sortable(),

            Badge::make('Verwijderd', fn (): string => $this->model()->deleted_at === null ? 'Nee' : 'Ja')
                ->map(['Nee' => 'success', 'Ja' => 'danger'])
                ->exceptOnForms(),

            DateTime::make('Uitgenodigd op', 'invited_at')->sortable()->readonly(),
            DateTime::make('Geactiveerd op', 'activated_at')->sortable()->readonly(),
            DateTime::make('Laatste login', 'last_login_at')->sortable()->readonly(),
            DateTime::make('Verwijderd op', 'deleted_at')->onlyOnDetail(),
            DateTime::make('Aangemaakt op', 'created_at')->onlyOnDetail(),
        ];
    }

    /**
     * Deleted users are part of what an operator comes here to see — finding somebody to restore is
     * the whole reason to look — so the roster shows them rather than hiding them behind a filter.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::includingDeleted($query)->orderBy('name');
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return self::includingDeleted($query);
    }

    /**
     * Drops the soft-delete scope.
     *
     * Nova types its query hooks against the Eloquent builder *contract*, which does not describe
     * `withTrashed()` — that arrives with the SoftDeletes trait. Removing the scope by name is the
     * same query, stated in terms the contract does have.
     */
    private static function includingDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class);
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
