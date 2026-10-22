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
 * Expects {@see self::RELATIONS} to be loaded, and every caller loads them. They used to be
 * `whenLoaded`, which made them optional on the documented schema while every response carried
 * them — so each endpoint was documented as the schema plus an anonymous object restating which of
 * its fields were really there.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * What every caller loads before handing a user to this resource: one list, so a field that
     * needs another relation is added here and not at each of the places a user is answered with.
     * Loaded with `with()` for a page, so the roster stays one query per relation.
     */
    public const array RELATIONS = ['organization.logo', 'outstandingInvitations'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'organizationName' => $this->organization->name,

            // For the application's header (KOM-112). Null when the organization has not uploaded
            // a logo, so the header can show its own rather than the placeholder this address
            // would answer with. Read with the caller's credential: a browser's `<img>` sends the
            // session cookie, a client with a token fetches it.
            'organizationLogoUrl' => $this->organization->logo === null ? null : '/api/organizations/'.$this->organization_id.'/logo',
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
