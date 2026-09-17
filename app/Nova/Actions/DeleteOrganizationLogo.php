<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\DeleteOrganizationLogoAction;
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
 * `DELETE /api/organizations/{organization}/logo`, as a button.
 *
 * Letting go of a file is an event, so the row goes before the bytes and the bytes are removed
 * after the commit. An organization with no logo is answered, not ignored.
 */
final class DeleteOrganizationLogo extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly DeleteOrganizationLogoAction $delete)
    {
        $this->confirmText = __('nova.actions.delete_organization_logo.confirm');
        $this->confirmButtonText = __('nova.actions.delete_organization_logo.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.delete_organization_logo.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $organization),
            (string) __('nova.actions.delete_organization_logo.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
