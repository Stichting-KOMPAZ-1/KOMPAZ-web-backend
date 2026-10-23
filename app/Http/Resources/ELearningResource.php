<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Chapter;
use App\Models\ELearning;
use App\Support\Modules\CoursePath;
use App\Support\Modules\SharedCourse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course with its contents: every chapter, and the name of every step inside them.
 *
 * The whole tree in one response, because that is what the sidebar of a step page shows and asking
 * for it a chapter at a time would be a request per heading. What is deliberately absent is the
 * content of the steps themselves — those are fetched one at a time, as they are read.
 *
 * @mixin ELearning
 */
final class ELearningResource extends JsonResource
{
    public static $wrap = null;

    /** How the course was reached through a shared link, when it was; null for a signed-in reader. */
    private ?SharedCourse $sharedCourse = null;

    /**
     * The same course, read through another door: a shared link rather than a signed-in reader.
     * Its picture's address hangs off that door's path ({@see CoursePath}), and the organization
     * that shared it is named.
     */
    public function readThrough(SharedCourse $course): self
    {
        $this->sharedCourse = $course;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => ($this->sharedCourse?->path() ?? CoursePath::signedIn($this->resource)).'/image',

            // The organization that shared the course, for the header of the public page
            // (KOM-110). Null for a signed-in reader, who has their own organization already.
            'sharedBy' => $this->sharedCourse === null ? null : new SharedOrganizationResource($this->sharedCourse),

            // Public readers get the contacts belonging to the module named by their link. Old
            // printed links merge the eligible modules and remove repeated e-mail addresses.
            'contacts' => $this->sharedCourse === null
                ? []
                : ModuleContactResource::collection($this->sharedCourse->contacts())->toArray($request),

            // Every chapter in reading order — the numbered ones, then the summaries — which is
            // also the list a reorder sends back. The two halves are given apart as well, because a
            // summary is drawn differently and a numbered chapter's number is its place in its own.
            'chapters' => ChapterResource::collection($this->chapters)->toArray($request),
            'numberedChapters' => ChapterResource::collection($this->chapters->reject(fn (Chapter $chapter): bool => $chapter->is_summary)->values())->toArray($request),
            'summaryChapters' => ChapterResource::collection($this->chapters->filter(fn (Chapter $chapter): bool => $chapter->is_summary)->values())->toArray($request),
            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
    }
}
