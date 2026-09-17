<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\ResendUserInvitationAction;
use App\Models\User;
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
 * `POST /api/users/{user}/invitations`, as a button.
 *
 * Safe to press twice: re-inviting somebody still waiting issues a fresh link and sends it, and
 * the use case refuses once the invitation has been accepted.
 */
final class ResendInvitation extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly ResendUserInvitationAction $resend)
    {
        $this->confirmText = __('nova.actions.resend_invitation.confirm');
        $this->confirmButtonText = __('nova.actions.resend_invitation.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.resend_invitation.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $user = $this->target($models, User::class);

        return $this->attempt(
            fn () => $this->resend->execute($this->operator(), $user),
            (string) __('nova.actions.resend_invitation.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
