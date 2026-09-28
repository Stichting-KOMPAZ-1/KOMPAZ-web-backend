<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\ELearning as ELearningModel;
use App\Models\User as UserModel;
use App\Support\Access\OrganizationAccess;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The courses, as an operator sees them.
 *
 * Read-only for now, and honestly so: authoring a course — its chapters, its steps and the blocks
 * inside them — is its own piece of work (KOM-45 and KOM-56 through KOM-60) and is not built yet.
 * What this resource exists for today is the module form, which needs somewhere to attach courses
 * from, and the listing KOM-44 asks for.
 *
 * Platform administrators only. A course carries no tenancy of its own — it is reached through the
 * modules that show it — so there is no scoped version of this listing to give anybody else, and
 * the ticket says so outright.
 */
/**
 * @extends \App\Nova\Resource<ELearningModel>
 */
class ELearning extends Resource
{
    /** @var class-string<ELearningModel> */
    public static $model = ELearningModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static function label(): string
    {
        return 'E-learnings';
    }

    public static function singularLabel(): string
    {
        return 'E-learning';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')->sortable()->readonly(),

            // The two numbers the listing is for: how big the course is, and whether anybody has
            // been given it. Both counted by the database rather than by loading the rows.
            Number::make('Hoofdstukken', fn (): int => (int) ($this->chapters_count ?? 0))
                ->exceptOnForms(),

            Text::make('In module(s)', fn (): string => $this->moduleNames())->exceptOnForms(),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query
            ->withCount('chapters')
            ->with('modules:id,name')
            ->orderByDesc('created_at');
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

    /** Only the platform sees the courses at all; the ticket is explicit about it. */
    public static function authorizedToViewAny(Request $request): bool
    {
        $operator = Auth::user();

        return $operator instanceof UserModel && OrganizationAccess::isPlatformAdministrator($operator);
    }

    /**
     * Writing is off until the authoring screens exist.
     *
     * Not an application of rule 18 — the content resources are exempt from that — but a statement
     * that half a form is worse than none: a course whose chapters cannot be edited from the same
     * place would invite an operator to create one they then cannot fill.
     */
    public static function authorizedToCreate(Request $request): bool
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
}
