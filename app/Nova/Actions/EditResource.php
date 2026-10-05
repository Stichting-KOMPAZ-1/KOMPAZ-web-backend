<?php

declare(strict_types=1);

namespace App\Nova\Actions;

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
use RuntimeException;

/**
 * "Bewerken" in a row's menu: the way into a record's edit form from a table.
 *
 * The product wants a table's operations in its "â€¦" menu and nowhere else (KOM-42), and Nova draws
 * the edit form's way in as a pencil beside that menu. The pencil is taken off every table in
 * {@see NovaResource::authorizedToUpdateForSerialization()}; this puts the same way in back where the
 * product wants it. It writes nothing â€” the form it opens does, under the resource's own rules â€” so
 * it is offered exactly where Nova would have drawn the pencil: on a row the operator may update.
 *
 * `visit` rather than a redirect, as {@see CompleteModuleInformation} does: Nova's own navigation,
 * so the panel moves to the form without the page reloading.
 */
final class EditResource extends Action
{
    use InteractsWithQueue, Queueable;

    /** @param  class-string<NovaResource<covariant Model>>  $editedResource  the resource whose edit form this opens */
    public function __construct(private readonly string $editedResource)
    {
        // Nothing to confirm: nothing happens until the form this opens is saved.
        $this->withoutConfirmation();
        $this->onlyInline();
        $this->sole = true;

        $this->canRun(static function (NovaRequest $request, mixed $model) use ($editedResource): bool {
            return $model instanceof Model && (new $editedResource($model))->authorizedToUpdate($request);
        });
    }

    /** @param  class-string<NovaResource<covariant Model>>  $resource */
    public static function for(string $resource): self
    {
        return new self($resource);
    }

    public function name(): string
    {
        return (string) __('nova.actions.edit_resource.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $model = $models->first();

        if (! $model instanceof Model) {
            throw new RuntimeException('Editing from a row expects exactly one record.');
        }

        return ActionResponse::visit(
            sprintf('resources/%s/%s/edit', $this->editedResource::uriKey(), $model->getKey()),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
