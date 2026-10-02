<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\InviteUserAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Nova\Concerns\RunsUseCase;
use App\Nova\Organization;
use App\Support\Access\OrganizationAccess;
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

/** `POST /api/users/invitations`, as a button. */
final class InviteUser extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly InviteUserAction $invite)
    {
        $this->confirmButtonText = __('nova.actions.invite_user.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.invite_user.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return $this->attempt(
            fn (): User => $this->invite->execute(
                $this->operator(),
                (string) $fields->get('email'),
                (string) $fields->get('name'),
                UserRole::from((string) $fields->get('role')),
                self::organizationId($fields),
            ),
            (string) __('nova.actions.invite_user.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        $picksTenant = fn (): bool => OrganizationAccess::isPlatformAdministrator($this->operator());

        return [
            Text::make((string) __('nova.actions.invite_user.field_name'), 'name')
                ->rules(['required', 'string', 'max:'.User::MAXIMUM_NAME_LENGTH]),

            Text::make((string) __('nova.actions.invite_user.field_email'), 'email')
                ->rules(['required', 'string', 'email', 'max:'.User::MAXIMUM_EMAIL_LENGTH]),

            // Only the roles the operator may grant: an organization administrator is never offered
            // the platform's own role, which the use case would refuse them on submission.
            Select::make((string) __('nova.actions.invite_user.field_role'), 'role')
                ->options(fn (): array => OrganizationAccess::grantableRoleOptions($this->operator()))
                ->rules(['required', Rule::enum(UserRole::class)]),

            // Which tenant somebody is invited into is the platform's choice to make. An
            // organization administrator invites into their own and nowhere else — which is
            // already what leaving this empty means to the use case, and what it would refuse
            // them for doing anything else. Offered only to be refused, it is a field that
            // cannot work, which is worse than no field. Hiding it decides the matter rather
            // than merely suggesting it: Nova drops a field the operator may not see from
            // validation and from the resolved payload alike, so one sent anyway is never read.
            Select::make((string) __('nova.actions.invite_user.field_organization'), 'organization')
                ->options(Organization::options())
                ->searchable()
                ->nullable()
                ->help((string) __('nova.actions.invite_user.organization_help'))
                ->rules(['nullable', 'uuid'])
                ->canSee($picksTenant),
        ];
    }

    /**
     * An empty picker means "mine", which is what a null organization means to the use case —
     * Nova sends an unset select as an empty string rather than as absent.
     */
    private static function organizationId(ActionFields $fields): ?string
    {
        $organizationId = $fields->get('organization');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
