<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
    public function __construct(Module $module, private readonly ?ModuleActivation $activation)
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

            'imageUrl' => $this->image() === null ? null : '/api/modules/'.$this->id.'/image',

            'videos' => $this->mergedVideos()
                ->map(fn (ModuleVideo $video): ModuleVideoResource => new ModuleVideoResource($video, (string) $this->id))
                ->values()
                ->all(),
            'links' => ModuleLinkResource::collection($this->mergedLinks())->toArray($request),
            'contacts' => ModuleContactResource::collection($this->contacts())->toArray($request),

            'eLearnings' => ELearningSummaryResource::collection($this->eLearnings)->toArray($request),

            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
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
