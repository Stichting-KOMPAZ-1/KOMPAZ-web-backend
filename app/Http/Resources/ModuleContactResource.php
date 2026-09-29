<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ModuleContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who to ring about a module. Always the reading organization's own — there is no shared contact,
 * by design.
 *
 * @mixin ModuleContact
 */
final class ModuleContactResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'reason' => $this->reason,
            'availability' => $this->availability,
        ];
    }
}
