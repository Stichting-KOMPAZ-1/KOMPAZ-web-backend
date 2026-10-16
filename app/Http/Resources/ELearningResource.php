<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Chapter;
use App\Models\ELearning;
use App\Support\Modules\CoursePath;
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

    /** Where the course was read, which its picture's address hangs off ({@see CoursePath}). */
    private ?string $coursePath = null;

    /** The same course, read through another door: a shared link rather than a signed-in reader. */
    public function readThrough(string $coursePath): self
    {
        $this->coursePath = $coursePath;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => ($this->coursePath ?? CoursePath::signedIn($this->resource)).'/image',
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
