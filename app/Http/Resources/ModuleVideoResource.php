<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleVideo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A video on a module, in whichever of its two shapes it was stored.
 *
 * Exactly one of `url` and `fileUrl` is set, which is what the table already guarantees. A client
 * embeds the first and fetches the second, and needs no flag to tell them apart.
 *
 * The module is handed in rather than read off the row. An organization's own video hangs off its
 * activation and has no `module_id` of its own, so deriving it would mean loading the activation —
 * one query per video, on the list this resource exists to render.
 *
 * @mixin ModuleVideo
 */
final class ModuleVideoResource extends JsonResource
{
    public static $wrap = null;

    /** @param  string  $moduleId  the module this video is being shown under, which its file address needs */
    /** @param  string|null  $organizationId  the organization the copy was read as by name, which its own videos are then addressed under */
    public function __construct(ModuleVideo $video, private readonly string $moduleId, private readonly ?string $organizationId = null)
    {
        parent::__construct($video);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            // An organization's own upload, read as that organization by name, is addressed under
            // it, so the platform reading as the organization can play it too.
            'fileUrl' => $this->isLinked() ? null : $this->fileUrl(),
            'isOrganizationSpecific' => $this->module_activation_id !== null,
        ];
    }

    private function fileUrl(): string
    {
        return $this->module_activation_id !== null && $this->organizationId !== null
            ? sprintf('/api/modules/%s/organizations/%s/videos/%s/file', $this->moduleId, $this->organizationId, $this->id)
            : sprintf('/api/modules/%s/videos/%s/file', $this->moduleId, $this->id);
    }
}
