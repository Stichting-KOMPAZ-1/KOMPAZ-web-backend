<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization as exposed over the API, including how its people are split between invited and
 * active.
 *
 * All three counts leave deleted users out, the way every other query over users states it. They
 * have to: the roster behind "Gebruikers (n)" leaves them out, and a count that included them would
 * promise a row of people the list it expands into does not name.
 *
 * @mixin Organization
 */
final class OrganizationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isPlatform' => $this->is_platform,
            'isArchived' => $this->isArchived(),
            'archivedUtc' => $this->archived_at?->toIso8601String(),
            'hasLogo' => $this->logo !== null,
            'userCount' => (int) ($this->users_count ?? 0),
            'activeUserCount' => (int) ($this->active_users_count ?? 0),
            'invitedUserCount' => (int) ($this->invited_users_count ?? 0),
            'createdUtc' => $this->created_at->toIso8601String(),
            'updatedUtc' => $this->updated_at->toIso8601String(),

            // Always populated, and always answers: an organization with no uploaded logo is served
            // the placeholder, so a client has one address to point at rather than a branch and a
            // copy of the fallback image. `hasLogo` is for a client that needs to tell the two
            // apart, such as one offering to replace an image that is really there.
            'logoUrl' => '/api/organizations/'.$this->id.'/logo',
        ];
    }
}
