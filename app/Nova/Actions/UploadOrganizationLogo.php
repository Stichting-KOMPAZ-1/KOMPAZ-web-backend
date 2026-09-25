<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\UploadOrganizationLogoAction;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Images\AcceptableLogo;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `PUT /api/organizations/{organization}/logo`, as a form.
 *
 * The two rejections an upload can meet — too large, and not an image this accepts — are the same
 * {@see AcceptableLogo} the API's form request applies, so both answer in the same words. The bytes
 * are then handed to the use case, which reads the format out of them again: the media type is a
 * property of the file, never of the upload that carried it.
 */
final class UploadOrganizationLogo extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(private readonly UploadOrganizationLogoAction $upload)
    {
        $this->confirmButtonText = __('nova.actions.upload_organization_logo.confirm_button');
        $this->cancelButtonText = __('nova.actions.cancel_button');
    }

    public function name(): string
    {
        return (string) __('nova.actions.upload_organization_logo.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $organization = $this->target($models, Organization::class);
        $logo = $fields->get('logo');

        if (! $logo instanceof UploadedFile) {
            return ActionResponse::danger(OrganizationMessages::LOGO_REQUIRED);
        }

        $contents = (string) file_get_contents($logo->getRealPath());

        return $this->attempt(
            fn (): Organization => $this->upload->execute($this->operator(), $organization, $contents),
            (string) __('nova.actions.upload_organization_logo.message'),
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            File::make((string) __('nova.actions.upload_organization_logo.field_logo'), 'logo')
                ->rules(['required', 'file', new AcceptableLogo]),
        ];
    }
}
