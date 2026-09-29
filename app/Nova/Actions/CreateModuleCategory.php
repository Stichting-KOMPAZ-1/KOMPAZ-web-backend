<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\CreateModuleCategoryAction;
use App\Models\ModuleCategory;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Modules\CategoryName;
use App\Support\Modules\ModuleMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `POST /api/module-categories`, as a dialog. A name already taken is answered under the field,
 * so the dialog stays open on the word that has to change.
 */
final class CreateModuleCategory extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Categorie aanmaken';

    public $confirmButtonText = 'Aanmaken';

    public $cancelButtonText = ModuleMessages::CANCEL_BUTTON;

    public function __construct(private readonly CreateModuleCategoryAction $create) {}

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return $this->attempt(
            fn (): ModuleCategory => $this->create->execute($this->operator(), (string) $fields->get('name')),
            ModuleMessages::CATEGORY_CREATED,
            refusalField: 'name',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Naam', 'name')->rules([new CategoryName]),
        ];
    }
}
