<?php

declare(strict_types=1);

namespace App\Nova\Actions;

use App\Models\ELearning;
use App\Models\Organization;
use App\Nova\Concerns\RunsUseCase;
use App\Support\Access\ModuleAccess;
use App\Support\Links\ShareLink;
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
 * "Delen": the link and the QR code an organization hands its people for a course (KOM-95).
 *
 * One dialog, as the ticket draws it: the operator picks an organization in it and sees that
 * organization's link and QR code at once. It used to be two — a Nova dialog to pick, and only
 * after confirming a second one with the link — and the link was looked for in the first, where it
 * never appeared. So the action asks nothing and answers straight away with the panel's own dialog
 * ({@see https://nova.laravel.com/docs/actions/defining-actions#modal-responses}), carrying the
 * link and the QR code's address for every organization the course is shared with; picking one
 * only chooses which to show. Only those organizations, because a link for any other would lead
 * to a 404. Nothing is written: the link exists as soon as the organization has a module showing
 * the course.
 *
 * The platform's alone, as everything on an e-learning is; an organization administrator does not
 * see e-learnings in the panel at all.
 */
final class ShareELearning extends Action
{
    use InteractsWithQueue, Queueable, RunsUseCase;

    /** The panel's own dialog the answer opens in, registered by `resources/nova/panel`. */
    public const string MODAL = 'share-e-learning-modal';

    public function __construct()
    {
        $this->sole();
        // Nothing to confirm and nothing to fill in: the dialog it opens is where the choice is.
        $this->withoutConfirmation();
    }

    public function name(): string
    {
        return (string) __('nova.actions.share_e_learning.name');
    }

    /** @param  Collection<int, Model>  $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        ModuleAccess::ensureCanManageContent($this->operator());

        $eLearning = $this->target($models, ELearning::class);

        $organizations = ModuleAccess::organizationsSharing($eLearning)->orderBy('name')->get();

        if ($organizations->isEmpty()) {
            return ActionResponse::danger((string) __('nova.actions.share_e_learning.nowhere'));
        }

        return ActionResponse::modal(self::MODAL, [
            'title' => (string) __('nova.actions.share_e_learning.title'),
            'eLearning' => $eLearning->name,
            'organizations' => $organizations
                ->map(static fn (Organization $organization): array => self::shareFor($organization, $eLearning))
                ->values()
                ->all(),
            'labels' => [
                'intro' => (string) __('nova.actions.share_e_learning.intro'),
                'organization' => (string) __('nova.actions.share_e_learning.field_organization'),
                'choose' => (string) __('nova.actions.share_e_learning.choose'),
                'link' => (string) __('nova.actions.share_e_learning.link'),
                'copy' => (string) __('nova.actions.share_e_learning.copy'),
                'copied' => (string) __('nova.actions.share_e_learning.copied'),
                'copyFailed' => (string) __('nova.actions.share_e_learning.copy_failed'),
                'qrCode' => (string) __('nova.actions.share_e_learning.qr_code'),
                'download' => (string) __('nova.actions.share_e_learning.download'),
                'close' => (string) __('nova.actions.share_e_learning.close'),
            ],
        ]);
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }

    /**
     * What the dialog shows for one organization: its link, and its QR code to draw and download.
     *
     * @return array{id: string, name: string, link: string, qrCodeUrl: string, qrCodeDownloadUrl: string}
     */
    private static function shareFor(Organization $organization, ELearning $eLearning): array
    {
        $qrCode = route('nova.share-qr-code', ['eLearning' => $eLearning->getKey(), 'organization' => $organization->getKey()]);

        return [
            'id' => (string) $organization->getKey(),
            'name' => $organization->name,
            'link' => ShareLink::direct($organization, $eLearning),
            'qrCodeUrl' => $qrCode,
            'qrCodeDownloadUrl' => $qrCode.'?download=1',
        ];
    }
}
