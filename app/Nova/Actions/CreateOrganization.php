<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\CreateOrganizationAction;
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
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `POST /api/organizations`, as a button.
 *
 * The name has to be unique folded, and the use case is what says so — an operator who picks a
 * name that differs only in case is told the same sentence a caller is.
 */
final class CreateOrganization extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly CreateOrganizationAction $create)
    {
        $this->confirmButtonText = __('nova.actions.create_organization.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.create_organization.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return $this->attempt(
            fn (): Organization => $this->create->execute((string) $fields->get('name')),
            (string) __('nova.actions.create_organization.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make((string) __('nova.actions.create_organization.field_name'), 'name')
                ->rules(['required', 'string', 'max:'.Organization::MAXIMUM_NAME_LENGTH]),
        ];
    }
}
