<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Users\DeleteUserAction;
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
 * `DELETE /api/users/{user}`, as a button.
 *
 * The panel calls this archiving although the use case is called deleting, because the panel also
 * offers {@see PurgeUser} — and between two buttons that both end an account, the words have to
 * say which one can be undone. Nothing else differs: this is the same request the API answers, and
 * the row it marks is the row {@see RestoreUser} puts back.
 *
 * The use case refuses an operator deleting their own account, and refuses one that would leave an
 * organization with no administrator — so those answers arrive here as the banner they are, rather
 * than as rules this form had to know about.
 */
final class ArchiveUser extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly DeleteUserAction $delete)
    {
        $this->confirmText = __('nova.actions.archive_user.confirm');
        $this->confirmButtonText = __('nova.actions.archive_user.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.archive_user.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $user = $this->target($models, User::class);

        return $this->attempt(
            fn () => $this->delete->execute($this->operator(), $user),
            (string) __('nova.actions.archive_user.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
