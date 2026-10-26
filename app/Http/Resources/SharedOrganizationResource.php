<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use App\Support\Modules\SharedCourse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization that shared a course, as a visitor without an account sees it (KOM-110): its
 * name and its logo for the page's header, and its identifier so a platform administrator reading
 * the page can pick it as the organization to read the module as. Nothing else — no counts,
 * nothing a signed-in member is shown about their own organization. The identifier is no secret:
 * the slug in the address already names the organization.
 *
 * @mixin Organization
 */
final class SharedOrganizationResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(private readonly SharedCourse $course)
    {
        parent::__construct($course->organization);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // False when the organization never uploaded one; the address then answers with the
            // placeholder, which a page may prefer to replace with its own logo.
            'hasLogo' => $this->logo !== null,

            // On the shared door, so it asks for no token and an `<img src>` can use it directly.
            'logoUrl' => $this->course->organizationLogoPath(),
        ];
    }
}
