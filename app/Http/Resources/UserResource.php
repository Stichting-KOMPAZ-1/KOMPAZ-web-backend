<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as exposed over the API.
 *
 * The member names are camel-cased rather than the snake_case the columns use, because they are the
 * contract the frontend already reads.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'organizationName' => $this->whenLoaded('organization', fn (): string => $this->organization->name),
            'email' => $this->email,
            'name' => $this->name,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'createdUtc' => $this->created_at->toIso8601String(),
            'invitedUtc' => $this->invited_at?->toIso8601String(),
            'activatedUtc' => $this->activated_at?->toIso8601String(),
            'lastLoginUtc' => $this->last_login_at?->toIso8601String(),

            // Read off the outstanding invitation rather than worked out from `invitedUtc` and the
            // configured lifetime: the link's expiry was fixed when it was issued, so reconfiguring
            // that lifetime cannot retroactively expire or revive one. At most one invitation is
            // outstanding per user, because issuing a new one retires the last.
            'invitationExpiresUtc' => $this->whenLoaded(
                'outstandingInvitations',
                fn (): ?string => $this->outstandingInvitations
                    ->max('expires_at')?->toIso8601String(),
            ),
            'deletedUtc' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
