<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category a module is filed under, for a form's picker and a listing's filter.
 *
 * @mixin ModuleCategory
 */
final class ModuleCategoryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
