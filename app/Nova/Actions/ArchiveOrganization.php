<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\ArchiveOrganizationAction;
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
 * `POST /api/organizations/{organization}/archive`, as a button.
 *
 * Styled as a destructive action although nothing is destroyed: it signs every member out and
 * stops them coming back, and an operator deciding whether to click deserves the warning that
 * matches the consequence rather than the one that matches the row count.
 */
final class ArchiveOrganization extends DestructiveAction
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly ArchiveOrganizationAction $archive)
    {
        $this->confirmText = __('nova.actions.archive_organization.confirm');
        $this->confirmButtonText = __('nova.actions.archive_organization.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.archive_organization.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);

        return $this->attempt(
            fn () => $this->archive->execute($this->operator(), $organization),
            (string) __('nova.actions.archive_organization.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
