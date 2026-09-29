<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\RenameModuleCategoryAction;
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

/** `PUT /api/module-categories/{category}`, as a dialog opened on the current name. */
final class RenameModuleCategory extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Hernoemen';

    public $confirmButtonText = 'Opslaan';

    public $cancelButtonText = ModuleMessages::CANCEL_BUTTON;

    public function __construct(private readonly RenameModuleCategoryAction $rename) {}

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $category = $this->target($models, ModuleCategory::class);

        return $this->attempt(
            fn (): ModuleCategory => $this->rename->execute($this->operator(), $category, (string) $fields->get('name')),
            ModuleMessages::CATEGORY_RENAMED,
            refusalField: 'name',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        $category = $this->selected(ModuleCategory::class);

        return [
            Text::make('Naam', 'name')
                ->default($category?->getAttribute('name'))
                ->rules([new CategoryName]),
        ];
    }
}
