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
use Illuminate\Validation\Rule;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * "Delen": the link and the QR code an organization hands its people for a course (KOM-95).
 *
 * Two steps, as the ticket draws them. The operator picks an organization — only those the course
 * is shared with are offered, because a link for any other would lead to a 404 — and the answer is
 * a dialog of the panel's own ({@see https://nova.laravel.com/docs/actions/defining-actions#modal-responses})
 * with the link to copy and the QR code to download. Nothing is written: the link exists as soon as
 * the organization has a module showing the course, and this only shows it.
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
        $this->confirmText = (string) __('nova.actions.share_e_learning.confirm');
        $this->confirmButtonText = (string) __('nova.actions.share_e_learning.confirm_button');
        $this->cancelButtonText = (string) __('nova.actions.cancel_button');
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

        $organization = ModuleAccess::organizationsSharing($eLearning)
            ->whereKey((string) $fields->get('organization'))
            ->first();

        if (! $organization instanceof Organization) {
            return ActionResponse::danger((string) __('nova.actions.share_e_learning.not_shared'));
        }

        $qrCode = route('nova.share-qr-code', ['eLearning' => $eLearning->getKey(), 'organization' => $organization->getKey()]);

        return ActionResponse::modal(self::MODAL, [
            'title' => (string) __('nova.actions.share_e_learning.title'),
            'eLearning' => $eLearning->name,
            'organization' => $organization->name,
            'link' => ShareLink::direct($organization, $eLearning),
            'qrCodeUrl' => $qrCode,
            'qrCodeDownloadUrl' => $qrCode.'?download=1',
            'labels' => [
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
        $options = $this->sharedWith();

        return [
            Select::make((string) __('nova.actions.share_e_learning.field_organization'), 'organization')
                ->options($options)
                ->rules(['required', Rule::in(array_keys($options))])
                ->help((string) __($options === []
                    ? 'nova.actions.share_e_learning.nowhere'
                    : 'nova.actions.share_e_learning.organization_help')),
        ];
    }

    /**
     * The organizations this course is shared with, by name, which are the only ones a link can be
     * made for.
     *
     * @return array<string, string>
     */
    private function sharedWith(): array
    {
        $eLearning = $this->selected(ELearning::class);

        if ($eLearning === null) {
            return [];
        }

        $options = [];

        foreach (ModuleAccess::organizationsSharing($eLearning)->orderBy('name')->get(['id', 'name']) as $organization) {
            $options[(string) $organization->getKey()] = $organization->name;
        }

        return $options;
    }
}
