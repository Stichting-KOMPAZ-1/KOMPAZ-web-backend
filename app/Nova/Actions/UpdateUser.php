<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\UpdateUserAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Nova\Concerns\RunsUseCase;
use App\Nova\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `PUT /api/users/{user}`, as a form.
 *
 * Name and email are required and prefilled, exactly as the API requires them on every edit. Role
 * and organization are optional there and optional here: left alone, they are not part of the
 * change, which is what keeps this form from being a way to move somebody by forgetting a field.
 */
final class UpdateUser extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly UpdateUserAction $update)
    {
        $this->confirmButtonText = __('nova.actions.update_user.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.update_user.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $user = $this->target($models, User::class);
        $role = $fields->get('role');
        $organizationId = $fields->get('organization');

        return $this->attempt(
            fn (): User => $this->update->execute(
                $this->operator(),
                $user,
                (string) $fields->get('name'),
                (string) $fields->get('email'),
                is_string($role) && $role !== '' ? UserRole::from($role) : null,
                is_string($organizationId) && $organizationId !== '' ? $organizationId : null,
            ),
            (string) __('nova.actions.update_user.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        $user = $this->selected($request, User::class);

        return [
            Text::make((string) __('nova.actions.update_user.field_name'), 'name')
                ->default($user?->name)
                ->rules(['required', 'string', 'max:'.User::MAXIMUM_NAME_LENGTH]),

            Text::make((string) __('nova.actions.update_user.field_email'), 'email')
                ->default($user?->email)
                ->rules(['required', 'string', 'email', 'max:'.User::MAXIMUM_EMAIL_LENGTH]),

            Select::make((string) __('nova.actions.update_user.field_role'), 'role')
                ->options(array_combine(UserRole::values(), UserRole::values()))
                ->nullable()
                ->help((string) __('nova.actions.update_user.role_help'))
                ->rules(['nullable', Rule::enum(UserRole::class)]),

            Select::make((string) __('nova.actions.update_user.field_organization'), 'organization')
                ->options(Organization::options())
                ->searchable()
                ->nullable()
                ->help((string) __('nova.actions.update_user.organization_help'))
                ->rules(['nullable', 'uuid']),
        ];
    }
}
