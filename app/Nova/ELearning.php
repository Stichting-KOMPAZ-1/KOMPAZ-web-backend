<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\ELearning as ELearningModel;
use App\Models\User as UserModel;
use App\Support\Access\OrganizationAccess;
use App\Support\Images\AcceptableLogo;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The courses, as an operator writes them.
 *
 * A course is independent of any module: one can be shown by several, by one, or by none, and the
 * link is managed from the module's form. This resource owns the course itself — its name and its
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
 * Chapters and steps are still to come (KOM-57 through KOM-60). A course can be created and named
 * now, which is what attaching one to a module needs.
 */
/**
 * @extends \App\Nova\Resource<ELearningModel>
 */
class ELearning extends Resource
{
    use Concerns\StoresUploadedImage;

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

            Text::make('Naam', 'name')
                ->sortable()
                ->rules(['required', 'string', 'max:'.ELearningModel::MAXIMUM_NAME_LENGTH]),

            // Required, unlike a module's: the columns behind it are not nullable, because a course
            // is what somebody works through and it has no placeholder to fall back to. Optional on
            // an edit, where leaving the field alone means keeping the picture already there.
            Image::make('Afbeelding', 'image_storage_key')
                ->disk(config('filesystems.default'))
                ->creationRules(['required', new AcceptableLogo])
                ->updateRules(['nullable', new AcceptableLogo])
                ->store($this->storesImageUnder(ELearningModel::IMAGE_PREFIX))
                // Null on a create form: Nova builds the fields against a record that has no key
                // yet, and an address for a course that does not exist is not a missing thumbnail
                // but a 500 on the form itself.
                ->preview(fn (): ?string => $this->previewUrl())
                ->prunable(false)
                ->deletable(false),

            // The two numbers the listing is for: how big the course is, and whether anybody shows
            // it. Counted by the database rather than by loading the rows.
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
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Where the panel reads this course's picture back from, once there is one to read.
     *
     * Only the missing key is checked. A course that exists always has a picture — the columns are
     * not nullable, unlike a module's — so there is no second case where the address would be
     * wrong.
     */
    private function previewUrl(): ?string
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
     * What an operator can do here beyond the form.
     *
     * @return array<int, Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [
            app(Actions\DeleteELearning::class)->sole(),
        ];
    }

    /** Writing the platform's own content is the platform's job, never a tenant's. */
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

    /** Off for the reason a module's is: the deletion warning is the product's, not Nova's. */
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
