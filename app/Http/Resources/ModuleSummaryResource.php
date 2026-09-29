<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A module as it appears in a list: enough to draw a card, and nothing that would cost a query per
 * row to produce.
 *
 * Separate from {@see ModuleResource} rather than the same class with keys that come and go.
 * A client reading a list should not have to discover which fields a detail response adds by
 * finding them missing.
 *
 * @mixin Module
 */
final class ModuleSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->name,
            'status' => $this->status,

            // Null rather than an address that answers 404: some modules have no picture, and a
            // client that has to try the request to find out would fetch one for every card.
            'imageUrl' => $this->image() === null ? null : '/api/modules/'.$this->id.'/image',

            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'updatedUtc' => $this->updated_at->toIso8601String(),
        ];
    }
}
