<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Organizations\DeleteOrganizationLogoAction;
use App\Actions\Organizations\UploadOrganizationLogoAction;
use App\Enums\UserStatus;
use App\Http\Requests\UploadOrganizationLogoRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use App\Support\Images\ServedLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The one image an organization has, and the placeholder that stands in for it. */
final readonly class OrganizationLogoController
{
    /**
     * Returns the organization's logo, or the placeholder when it has none.
     *
     * Behind the bearer token like everything else here, because which organizations exist is not
     * public. A browser cannot put a token on an `<img src>`, so a client fetches this and hands
     * the response to the element as an object URL.
     */
    public function show(Request $request, Organization $organization): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        OrganizationAccess::ensureCanRead($actor, (string) $organization->getKey());

        $logo = ServedLogo::for($organization);

        return response($logo->content, HttpResponse::HTTP_OK, $logo->headers());
    }

    /** Sets or replaces the organization's logo, and answers with the organization. */
    public function update(
        UploadOrganizationLogoRequest $request,
        Organization $organization,
        UploadOrganizationLogoAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $organization, $request->contents());

        return OrganizationResource::make(
            Organization::query()
                ->withCount([
                    'users as users_count' => fn ($users) => $users->whereNull('deleted_at'),
                    'users as active_users_count' => fn ($users) => $users
                        ->whereNull('deleted_at')
                        ->where('status', UserStatus::Active->value),
                    'users as invited_users_count' => fn ($users) => $users
                        ->whereNull('deleted_at')
                        ->where('status', UserStatus::Invited->value),
                ])
                ->with('logo')
                ->whereKey($organization->getKey())
                ->firstOrFail(),
        )->response();
    }

    /** Removes the organization's logo, which puts it back to the placeholder. */
    public function destroy(
        Request $request,
        Organization $organization,
        DeleteOrganizationLogoAction $action,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $organization);

        return response()->noContent();
    }
}
