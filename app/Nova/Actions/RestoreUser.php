<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\RestoreUserAction;
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
 * `POST /api/users/{user}/restore`, as a button.
 *
 * Finding somebody to bring back is a reason an operator opens the panel at all, which is why the
 * roster shows deleted users rather than hiding them.
 */
final class RestoreUser extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly RestoreUserAction $restore)
    {
        $this->confirmText = __('nova.actions.restore_user.confirm');
        $this->confirmButtonText = __('nova.actions.restore_user.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.restore_user.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $user = $this->target($models, User::class);

        return $this->attempt(
            fn (): User => $this->restore->execute($this->operator(), $user),
            (string) __('nova.actions.restore_user.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
