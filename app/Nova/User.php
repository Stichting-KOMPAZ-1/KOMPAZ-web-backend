<?php

declare(strict_types=1);

namespace App\Nova;

use App\Enums\RosterStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User as UserModel;
use App\Nova\Filters\UserDeletionState;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
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

    /**
     * Eager loaded because every row's status asks whether the invitation it was sent can still be
     * accepted, and a roster of a hundred people would otherwise be a hundred queries.
     *
     * @var array<int, string>
     */
    public static $with = ['outstandingInvitations'];

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
                ->options(UserRole::options())
                ->sortable()
                ->readonly(),

            // Resolved from the row rather than read straight off the column, because the panel
            // shows a state the column does not have: an invitation whose link has run out. The
            // attribute stays `status`, so the column is still what the header sorts by — the two
            // invited states sort together, which is the order the column can express.
            Badge::make('Status', 'status')
                ->resolveUsing(fn (): string => $this->model()->rosterStatus(Carbon::now())->value)
                ->map([
                    RosterStatus::Active->value => 'success',
                    RosterStatus::Invited->value => 'warning',
                    RosterStatus::Expired->value => 'danger',
                ])
                ->sortable(),

            DateTime::make('Uitgenodigd op', 'invited_at')->onlyOnDetail(),
            DateTime::make('Geactiveerd op', 'activated_at')->onlyOnDetail(),
            DateTime::make('Laatste login', 'last_login_at')->onlyOnDetail(),
            DateTime::make('Verwijderd op', 'deleted_at')->onlyOnDetail(),
            DateTime::make('Aangemaakt op', 'created_at')->onlyOnDetail(),
        ];
    }

    /**
     * The roster is the people who are still here.
     *
     * Deleted users are not out of reach — {@see UserDeletionState} asks for them, which is how an
     * operator finds somebody to restore — but they are not mixed in with the rest, because a list
     * holding both has to say of every row on it which of the two it is.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /** @return array<int, Filter> */
    public function filters(NovaRequest $request): array
    {
        return [new UserDeletionState];
    }

    /**
     * A deleted user's own page opens, whichever list it was reached from: it is where the date of
     * the deletion is, and where the button that undoes it lives.
     */
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

    /**
     * Nova's own soft-delete machinery stays off, although the model does soft-delete.
     *
     * Switching it on hands the panel a second set of controls for the same thing: its own
     * "with trashed" selector beside the filter above, and — there being no policy here to refuse
     * them — a restore and a *force* delete on every deleted row, each writing straight to the
     * database. Restoring is {@see Actions\RestoreUser}, on top of the use case the API calls, and
     * nothing in this application deletes a user for good.
     */
    public static function softDeletes(): bool
    {
        return false;
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
     * user is reachable through the filter on purpose, and none of the API's write endpoints
     * accept one, so only restoring is offered there.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            app(Actions\InviteUser::class)->standalone(),

            // Not offered on an invitation. There is no account behind it yet — only a name and an
            // address on a link that has already gone out — so the way to correct one is to
            // withdraw it and invite again, which sends the corrected link.
            app(Actions\UpdateUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted() && $user->status !== UserStatus::Invited),

            // Only offered while there is an invitation to resend. Somebody who has accepted one
            // has no pending link, and the use case refuses them — so showing the button would
            // promise a fresh mail and then answer with a banner saying it was already accepted.
            app(Actions\ResendInvitation::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted() && $user->status !== UserStatus::Active),

            app(Actions\RestoreUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => $user->isDeleted()),

            app(Actions\DeleteUser::class)
                ->sole()
                ->canRun(static fn (NovaRequest $request, UserModel $user): bool => ! $user->isDeleted()),
        ];
    }
}
