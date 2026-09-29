<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\DeleteModuleCategoryAction;
use App\Models\ModuleCategory;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Modules\ModuleMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Actions\DestructiveAction;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `DELETE /api/module-categories/{category}`. One still in use is refused with a banner naming how
 * many modules wear it — there is nothing in this dialog to correct, the modules have to move.
 */
final class DeleteModuleCategory extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Verwijderen';

    public $confirmText = ModuleMessages::DELETE_CATEGORY_CONFIRMATION;

    public $confirmButtonText = ModuleMessages::DELETE_MODULE_CONFIRM_BUTTON;

    public $cancelButtonText = ModuleMessages::CANCEL_BUTTON;

    public function __construct(private readonly DeleteModuleCategoryAction $delete) {}

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $category = $this->target($models, ModuleCategory::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $category),
            ModuleMessages::CATEGORY_DELETED,
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
