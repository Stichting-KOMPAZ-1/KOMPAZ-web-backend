<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Models\ModuleActivation;
use App\Nova\Concerns\RunsUseCase;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The way into an organization's own copy of a module, under the words for what it does.
 *
 * The only action here that writes nothing — rule 18 is about operations that change data, and this
 * one opens the form that does. It exists because the control it replaces is Nova's pencil, whose
 * label is the one word "Bewerken" for every resource in the panel and cannot be given a different
 * one per resource from PHP. "Bewerken" is also wrong: an organization administrator cannot change
 * the module, which the platform wrote. What they can do is add their own videos, links and contact
 * details to their copy of it, which is what this says.
 *
 * `visit` rather than a redirect: Nova's own navigation, so the panel moves to the form without the
 * page reloading, exactly as pressing the pencil does.
 */
final class CompleteModuleInformation extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct()
    {
        // No confirmation: there is nothing to confirm, because nothing happens until the form
        // this opens is saved.
        $this->withoutConfirmation();
    }

    public function name(): string
    {
        return (string) __('nova.actions.complete_module_information.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $activation = $this->target($models, ModuleActivation::class);

        return ActionResponse::visit(
            sprintf('resources/%s/%s/edit', \App\Nova\ModuleActivation::uriKey(), $activation->getKey()),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
