<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User as UserModel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
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
 * The fields are read-only and Nova's own forms stay off, because a form that wrote these columns
 * directly would bypass the rules that make them true: who may grant which role, whether an
 * organization would be left with no administrator, whether the folded email column still matches
 * the address, whether the person is owed a notice. Everything an operator can do here is one of
 * the actions in {@see Actions}, each of which calls the same use case the API calls —
 * so the panel is as capable as the API and no more permissive.
 */
/**
 * @extends \App\Nova\Resource<UserModel>
 */
class User extends Resource
{
    /** @var class-string<UserModel> */
    public static $model = UserModel::class;

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

    /**
     * Refused so that Nova's own create, edit and delete stay off.
     *
     * Not a statement that an operator may not do these things — they may, through the actions
     * below, which is what keeps one implementation of each rule instead of two.
     */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /**
     * What an operator can do here: the API's write endpoints for a user, each delegating to the
     * same use case.
     *
     * The gates below are about which button applies to the row in front of the operator, not
     * about who may press it — that question is the use case's, and it asks it again. A deleted
     * user is reachable on this roster on purpose, and none of the API's write endpoints accept
     * one, so only restoring is offered there.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            app(Actions\InviteUser::class)->standalone(),

            app(Actions\UpdateUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted()),

            app(Actions\ResendInvitation::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted()),

            app(Actions\RestoreUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => $user->isDeleted()),

            app(Actions\DeleteUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted()),
        ];
    }
}
