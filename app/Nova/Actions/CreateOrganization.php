<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\UploadOrganizationLogoAction;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Images\AcceptableLogo;
use App\Support\Images\LogoImage;
use App\Support\Organizations\OrganizationName;
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
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * `POST /api/organizations`, as a form — with the logo upload that follows it.
 *
 * Two endpoints, because an organization is created before it has an identifier to store a file
 * under, and the API has always offered them separately. What the operator sees is one dialog: a
 * new organization arrives with the image it is meant to be shown with, rather than with the
 * placeholder until somebody remembers a second step. The logo is optional, and leaving it out is
 * the ordinary case rather than a mistake.
 *
 * Both rejections the name can meet — blank, and already taken folded — are answered under the
 * field rather than as a banner, so the dialog stays open on the value that has to change.
 */
final class CreateOrganization extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    public function __construct(
        private readonly CreateOrganizationAction $create,
        private readonly UploadOrganizationLogoAction $uploadLogo,
    ) {
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
        $logo = $fields->get('logo');

        return $this->attempt(
            function () use ($fields, $logo): void {
                $organization = $this->create->execute((string) $fields->get('name'));

                // After the organization, never before: the key a logo is stored under is minted
                // from the organization's identifier, which does not exist until it is saved. The
                // file has already passed the same rules the upload endpoint applies, so what is
                // left to fail here is the disk — which is a defect and answered as one, with the
                // organization created and its logo still to add.
                if ($logo instanceof UploadedFile) {
                    $this->uploadLogo->execute(
                        $this->operator(),
                        $organization,
                        (string) file_get_contents($logo->getRealPath()),
                    );
                }
            },
            (string) __('nova.actions.create_organization.message'),
            refusalField: 'name',
        );
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make((string) __('nova.actions.create_organization.field_name'), 'name')
                ->rules([new OrganizationName]),

            File::make((string) __('nova.actions.create_organization.field_logo'), 'logo')
                // The limit and the list of formats are quoted from what enforces them, so the
                // sentence under the field cannot promise something the rule then refuses.
                ->help((string) __('nova.actions.create_organization.logo_help', [
                    'formats' => LogoImage::ACCEPTED_FORMATS,
                    'size' => LogoImage::MAXIMUM_SIZE,
                ]))
                ->rules(['nullable', 'file', new AcceptableLogo]),
        ];
    }
}
