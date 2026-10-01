<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\ModuleCategory as ModuleCategoryModel;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The categories a module is filed under, and managing them (KOM-51).
 *
 * **Written through actions, not Nova's form** — rule 18 as it stands for users and organizations,
 * not the content carve-out: a category's name is unique folded (`normalized_name`), and a Nova
 * form writes the column it is given and nothing else. Creating, renaming and deleting are
 * {@see Actions\CreateModuleCategory}, {@see Actions\RenameModuleCategory} and
 * {@see Actions\DeleteModuleCategory}, over the same use cases the API calls.
 *
 * Platform administrators only.
 */
/**
 * @extends \App\Nova\Resource<ModuleCategoryModel>
 */
class ModuleCategory extends Resource
{
    use Concerns\AuthoredByThePlatform;

    /** @var class-string<ModuleCategoryModel> */
    public static $model = ModuleCategoryModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static function label(): string
    {
        return 'Categorieën';
    }

    public static function singularLabel(): string
    {
        return 'Categorie';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            Text::make('Naam', 'name')->sortable()->readonly(),

            // How many modules wear it, which is also whether it can be deleted at all.
            Number::make('Modules', fn (): int => (int) ($this->modules_count ?? 0))
                ->exceptOnForms(),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->withCount('modules');
    }

    /**
     * Alphabetical.
     *
     * Here rather than in `indexQuery`, because Nova adds a column the operator clicked *after*
     * whatever that query already orders by — so an order stated there wins every time, and the
     * table's sortable headers do nothing. Nova asks for this only when nothing was clicked.
     */
    public static function defaultOrderings(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->withCount('modules');
    }

    /** @return array<int, Action> */
    public function actions(NovaRequest $request): array
    {
        return [
            app(Actions\CreateModuleCategory::class)->standalone(),
            app(Actions\RenameModuleCategory::class)->sole()->showInline(),
            app(Actions\DeleteModuleCategory::class)->sole()->showInline(),
        ];
    }

    /** Refused: a Nova form would write the name without the folded column it is unique on. */
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
