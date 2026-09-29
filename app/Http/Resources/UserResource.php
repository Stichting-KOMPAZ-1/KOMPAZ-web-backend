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
 * Expects `organization` and `outstandingInvitations` to be loaded, and every caller loads them.
 * Both used to be `whenLoaded`, which made them optional on the documented schema while every
 * response carried them — so each endpoint was documented as the schema plus an anonymous object
 * restating which of its fields were really there.
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
            'organizationName' => $this->organization->name,
            'email' => $this->email,
            'name' => $this->name,
            'role' => $this->role,
            'status' => $this->status,
            /** @format date-time */
            'createdUtc' => $this->created_at->toIso8601String(),
            /** @format date-time */
            'invitedUtc' => $this->invited_at?->toIso8601String(),
            /** @format date-time */
            'activatedUtc' => $this->activated_at?->toIso8601String(),
            /** @format date-time */
            'lastLoginUtc' => $this->last_login_at?->toIso8601String(),

            /**
             * Read off the outstanding invitation rather than worked out from `invitedUtc` and the
             * configured lifetime: the link's expiry was fixed when it was issued, so reconfiguring
             * that lifetime cannot retroactively expire or revive one. At most one invitation is
             * outstanding per user, because issuing a new one retires the last.
             *
             * @format date-time
             */
            'invitationExpiresUtc' => $this->outstandingInvitations->max('expires_at')?->toIso8601String(),
            /** @format date-time */
            'deletedUtc' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
