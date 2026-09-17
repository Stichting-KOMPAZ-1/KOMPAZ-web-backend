<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
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
 * `DELETE /api/organizations/{organization}`, as a button.
 *
 * The organization that runs the platform cannot be deleted, and the use case is where that is
 * said — so the panel cannot be the one place it is forgotten.
 */
final class DeleteOrganization extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly DeleteOrganizationAction $delete)
    {
        $this->confirmText = __('nova.actions.delete_organization.confirm');
        $this->confirmButtonText = __('nova.actions.delete_organization.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.delete_organization.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $organization),
            (string) __('nova.actions.delete_organization.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
