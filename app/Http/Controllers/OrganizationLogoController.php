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
use App\Support\Images\PlaceholderLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

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

        $logo = $organization->logo;
        $content = null;
        $contentType = PlaceholderLogo::contentType();

        if ($logo !== null) {
            try {
                $content = Storage::get($logo->storage_key);
            } catch (Throwable $exception) {
                $content = null;

                Log::warning('A logo row names a file the disk does not have.', [
                    'organization_id' => $organization->getKey(),
                    'storage_key' => $logo->storage_key,
                    'exception' => $exception,
                ]);
            }

            if ($content !== null) {
                $contentType = $logo->content_type;
            }
        }

        // A row naming a file the disk does not have is reachable rather than theoretical — an
        // upload commits its row and a cleanup can fail the other way — so it is answered with the
        // placeholder, which is what an organization without a usable logo should look like. The
        // alternative, a 500, would turn one lost file into a page that will not render.
        $content ??= PlaceholderLogo::content();

        // An SVG is a document rather than a picture: served as itself it can carry script, and
        // this API's own origin is where that script would run. These two headers are what make it
        // harmless — the document may load nothing, and the browser may not decide for itself that
        // the bytes are something more interesting than the media type says. Set for every format,
        // because the upload chose which one this is.
        return response($content, HttpResponse::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
