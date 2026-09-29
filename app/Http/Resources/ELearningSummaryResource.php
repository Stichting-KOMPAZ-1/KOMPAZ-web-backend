<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ELearning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as it appears on a module: enough to link to it, and nothing of its contents.
 *
 * @mixin ELearning
 */
final class ELearningSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'imageUrl' => '/api/e-learnings/'.$this->id.'/image',
            'chapterCount' => (int) ($this->chapters_count ?? 0),
        ];
    }
}
