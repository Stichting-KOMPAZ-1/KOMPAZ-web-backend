<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\ModuleVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One module, as the organization reading it sees it.
 *
 * The videos and the links are two lists merged into one: the platform's, then the reading
 * organization's own. That is what the front end draws, and merging here rather than in the client
 * is what keeps one organization's additions from ever appearing under another's module — the
 * activation this is built with is the reader's, or none at all.
 *
 * Contacts are never merged, because there is nothing to merge them with. A contact belongs to an
 * activation and only to one, which is the whole point of the org-specific form.
 *
 * @mixin Module
 */
final class ModuleResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  ModuleActivation|null  $activation  the reader's own copy of this module, when they
     *                                             have one. A platform administrator has none, and
     *                                             sees the platform's material and nothing else.
     */
    /**
     * @param  bool  $namedOrganization  whether the request named the organization (`?organization=`),
     *                                   so the addresses inside name it too and answer the same way
     */
    public function __construct(Module $module, private readonly ?ModuleActivation $activation, private readonly bool $namedOrganization = false)
    {
        parent::__construct($module);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->name,
            // The key as well as the name, which is what a form editing the module pre-selects.
            'categoryId' => $this->category_id,
            'description' => $this->description,
            'sourceAttribution' => $this->source_attribution,
            'status' => $this->status,

            // Whose copy this is: the organization whose contacts, own videos and links and share
            // links follow. Null when the platform reads the module at no organization, which is
            // why those are empty then — not because nobody filled them in.
            'organization' => $this->activation === null ? null : new ModuleOrganizationResource($this->activation->organization),

            'imageUrl' => $this->image() === null ? null : '/api/modules/'.$this->id.'/image',

            'videos' => $this->mergedVideos()
                ->map(fn (ModuleVideo $video): ModuleVideoResource => new ModuleVideoResource($video, (string) $this->id, $this->addressedAs()))
                ->values()
                ->all(),
            'links' => ModuleLinkResource::collection($this->mergedLinks())->toArray($request),
            'contacts' => ModuleContactResource::collection($this->contacts())->toArray($request),

            // Each with the links the reader's organization shares it by, when they read at one.
            'eLearnings' => $this->eLearnings
                ->map(fn (ELearning $eLearning): ELearningSummaryResource => new ELearningSummaryResource(
                    $eLearning,
                    $this->activation?->organization,
                    (string) $this->id,
                    $this->addressedAs(),
                ))
                ->values()
                ->all(),

            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * The organization the addresses inside are under, when the request named one. A reader at
     * their own organization reaches the plain addresses; the platform reading as one does not.
     */
    private function addressedAs(): ?string
    {
        return $this->namedOrganization ? $this->activation?->organization_id : null;
    }

    /**
     * The platform's videos followed by the reader's own.
     *
     * @return Collection<int, ModuleVideo>
     */
    private function mergedVideos(): Collection
    {
        /** @var Collection<int, ModuleVideo> $platform */
        $platform = $this->videos;

        $own = $this->activation?->videos;

        return $platform->concat($own ?? []);
    }

    /**
     * The platform's links followed by the reader's own.
     *
     * @return Collection<int, ModuleLink>
     */
    private function mergedLinks(): Collection
    {
        /** @var Collection<int, ModuleLink> $platform */
        $platform = $this->links;

        $own = $this->activation?->links;

        return $platform->concat($own ?? []);
    }

    /**
     * The reader's own contacts, and nobody else's.
     *
     * @return Collection<int, ModuleContact>
     */
    private function contacts(): Collection
    {
        /** @var Collection<int, ModuleContact>|null $contacts */
        $contacts = $this->activation?->contacts;

        return $contacts ?? new Collection;
    }
}
