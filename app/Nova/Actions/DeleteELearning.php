<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\DeleteELearningAction;
use App\Models\ELearning;
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
 * Deleting a course, with the sentence the product wrote.
 *
 * Nova's own row delete is off for this resource for the reason it is off for a module: its
 * confirmation modal carries a generic sentence and no resource can give it one of its own, and
 * this copy is a promise — that the deletion cannot be undone, and that the modules linking to the
 * course are not deleted with it.
 */
class DeleteELearning extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Verwijderen';

    public $confirmText = ModuleMessages::DELETE_E_LEARNING_CONFIRMATION;

    public $confirmButtonText = ModuleMessages::DELETE_MODULE_CONFIRM_BUTTON;

    public $cancelButtonText = ModuleMessages::CANCEL_BUTTON;

    public function __construct(private readonly DeleteELearningAction $delete) {}

    /**
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $eLearning = $this->target($models, ELearning::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $eLearning),
            ModuleMessages::E_LEARNING_DELETED,
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
