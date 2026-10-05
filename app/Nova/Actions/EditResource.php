<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Nova\Concerns\RunsUseCase;
use App\Nova\Resource as NovaResource;
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
 * The way into a record's edit form from a table: "Bewerken" in a row's menu.
 *
 * The product wants a table's operations in its "…" menu and nowhere else (KOM-42), and Nova draws
 * the edit form's way in as a pencil beside that menu. The pencil is taken off every table in
 * {@see NovaResource::authorizedToUpdateForSerialization()}; this puts the same way in back where the
 * product wants it. It writes nothing — the form it opens does, under the resource's own rules — so
 * it is offered exactly where Nova would have drawn the pencil: on a row the operator may update.
 *
 * A resource whose form is for something narrower than editing names it: an organization's copy of
 * a module opens the same form as "Informatie aanvullen", because an organization administrator
 * cannot change the module the platform wrote, only add their own videos, links and contacts to it.
 *
 * `visit` rather than a redirect: Nova's own navigation, so the panel moves to the form without the
 * page reloading, exactly as pressing the pencil did.
 */
final class EditResource extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    /**
     * @param  class-string<NovaResource<covariant Model>>  $editedResource  the resource whose edit form this opens
     * @param  string|null  $label  what the menu calls it, when "Bewerken" is the wrong word
     */
    public function __construct(private readonly string $editedResource, private readonly ?string $label = null)
    {
        // Nothing to confirm: nothing happens until the form this opens is saved.
        $this->withoutConfirmation();
        $this->onlyInline();
        $this->sole();

        $this->canRun(static function (NovaRequest $request, mixed $model) use ($editedResource): bool {
            return $model instanceof Model && (new $editedResource($model))->authorizedToUpdate($request);
        });
    }

    /**
     * @param  class-string<NovaResource<covariant Model>>  $resource
     * @param  string|null  $label  what the menu calls it, when "Bewerken" is the wrong word
     */
    public static function for(string $resource, ?string $label = null): self
    {
        return new self($resource, $label);
    }

    public function name(): string
    {
        return $this->label ?? (string) __('nova.actions.edit_resource.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return ActionResponse::visit(
            sprintf('resources/%s/%s/edit', $this->editedResource::uriKey(), $this->target($models, Model::class)->getKey()),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
