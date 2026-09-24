<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\PurgeUserAction;
use App\Models\User;
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
 * The one operation the panel has and the API does not: removing a user for good.
 *
 * Every other button here is an API endpoint with a form on it, which is what keeps the panel from
 * being more permissive than the API. This one is deliberately the other way round — an operator's
 * cleanup tool, for an account that should never have existed and for an address that has to be
 * released — and it is still a use case in `app/Actions`, so the rules it enforces are the rules
 * everything else enforces rather than a second set living in a form.
 *
 * Offered on every row, deleted or not. Somebody archived first is the ordinary way round, but an
 * operator who already knows the account should not exist should not have to archive it to say so.
 */
final class PurgeUser extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly PurgeUserAction $purge)
    {
        $this->confirmText = __('nova.actions.purge_user.confirm');
        $this->confirmButtonText = __('nova.actions.purge_user.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.purge_user.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $user = $this->target($models, User::class);

        return $this->attempt(
            fn () => $this->purge->execute($this->operator(), $user),
            (string) __('nova.actions.purge_user.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
