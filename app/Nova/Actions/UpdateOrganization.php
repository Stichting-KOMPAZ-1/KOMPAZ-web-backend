<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\UpdateOrganizationAction;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Organizations\OrganizationName;
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

/**
 * `PUT /api/organizations/{organization}`, as a form.
 *
 * The name is judged by the same rule the create dialog uses, and a name already taken is answered
 * under the field rather than as a banner — renaming to a name that exists is a typo to correct,
 * not a reason to close the dialog and start again.
 */
final class UpdateOrganization extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly UpdateOrganizationAction $update)
    {
        $this->confirmButtonText = __('nova.actions.update_organization.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.update_organization.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        return $this->attempt(
            fn (): Organization => $this->update->execute(
                $this->operator(),
                $organization,
                (string) $fields->get('name'),
            ),
            (string) __('nova.actions.update_organization.message'),
            refusalField: 'name',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        $organization = $this->selected(Organization::class);

        return [
            Text::make((string) __('nova.actions.update_organization.field_name'), 'name')
                ->default($organization?->name)
                ->rules([new OrganizationName]),
        ];
    }
}
