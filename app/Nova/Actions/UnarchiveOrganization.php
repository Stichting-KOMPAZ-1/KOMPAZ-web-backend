<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\UnarchiveOrganizationAction;
use App\Models\Organization;
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

/** `DELETE /api/organizations/{organization}/archive`, as a button. */
final class UnarchiveOrganization extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly UnarchiveOrganizationAction $unarchive)
    {
        $this->confirmText = __('nova.actions.unarchive_organization.confirm');
        $this->confirmButtonText = __('nova.actions.unarchive_organization.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.unarchive_organization.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        return $this->attempt(
            fn () => $this->unarchive->execute($organization),
            (string) __('nova.actions.unarchive_organization.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
