<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Modules\DeleteModuleAction;
use App\Models\Module;
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
 * Deleting a module, with the sentence the product wrote.
 *
 * Nova's own row delete is off for this resource — the one place the content carve-out in rule 18
 * does not reach — because its confirmation modal carries a generic sentence and there is no way
 * to give one resource its own. The copy here is not decoration: it tells an operator that the
 * deletion cannot be undone *and* that the courses inside the module will survive it, which is the
 * question somebody about to click would otherwise have to guess at.
 *
 * Overriding Nova's global Dutch string would have worked today, because this is currently the
 * only resource with a native delete at all — and would have quietly become wrong for the next
 * one. A destructive action says which resource it belongs to.
 */
class DeleteModule extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public $name = 'Verwijderen';

    /** The sentence the product wrote, word for word. Asserted by a test so the two cannot drift. */
    public $confirmText = ModuleMessages::DELETE_MODULE_CONFIRMATION;

    public $confirmButtonText = ModuleMessages::DELETE_MODULE_CONFIRM_BUTTON;

    public $cancelButtonText = ModuleMessages::CANCEL_BUTTON;

    public function __construct(private readonly DeleteModuleAction $delete) {}

    /**
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $module = $this->target($models, Module::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $module),
            ModuleMessages::MODULE_DELETED,
        );
    }

    /**
     * No fields: everything this asks is in the sentence above the buttons.
     *
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
