<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\DeleteOrganizationAction;
use App\Actions\Organizations\UpdateOrganizationAction;
use App\Enums\UserStatus;
use App\Http\Requests\IndexOrganizationsRequest;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\PaginatedCollection;
use App\Models\Organization;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use App\Support\Pagination\PaginatedList;
use App\Support\Search\SearchPattern;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Tenant management. Platform administrators see every organization; everyone else only their own. */
final readonly class OrganizationController
{
    /** Returns a page of organizations. */
    public function index(IndexOrganizationsRequest $request): Responsable
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = Organization::query()->with('logo');

        if (! OrganizationAccess::isPlatformAdministrator($actor)) {
            $query->whereKey(OrganizationAccess::resolveTarget($actor, null));
        }

        $search = $request->validated('search');

        if (is_string($search) && trim($search) !== '') {
            // `normalized_name` is already folded, which is what it is for, so the fold the pattern
            // expects on both sides costs nothing here and the index on it stays usable.
            $query->whereRaw(
                'normalized_name LIKE ? ESCAPE ?',
                [SearchPattern::contains(trim($search)), SearchPattern::ESCAPE_CHARACTER],
            );
        }

        $page = PaginatedList::create(
            self::withMemberCounts($query)->orderBy('name')->orderBy('id'),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return new PaginatedCollection($page, OrganizationResource::class);
    }

    /** Returns a single organization. */
    public function show(Request $request, Organization $organization): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        OrganizationAccess::ensureCanRead($actor, (string) $organization->getKey());

        return OrganizationResource::make(self::loadForResponse($organization))->response();
    }

    /** Creates an organization. */
    public function store(StoreOrganizationRequest $request, CreateOrganizationAction $action): JsonResponse
    {
        $organization = $action->execute((string) $request->validated('name'));

        return OrganizationResource::make(self::loadForResponse($organization))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/organizations/'.$organization->getKey());
    }

    /** Renames an organization. */
    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        UpdateOrganizationAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->execute($actor, $organization, (string) $request->validated('name'));

        return OrganizationResource::make(self::loadForResponse($updated))->response();
    }

    /** Deletes an organization along with its users. */
    public function destroy(Request $request, Organization $organization, DeleteOrganizationAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $organization);

        return response()->noContent();
    }

    /**
     * Adds the three member counts the roster header shows, aggregated by the database rather than
     * by loading the people to count them.
     *
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
     */
    private static function withMemberCounts(Builder $query): Builder
    {
        return $query->withCount([
            'users as users_count' => fn (Builder $users) => $users->whereNull('deleted_at'),
            'users as active_users_count' => fn (Builder $users) => $users
                ->whereNull('deleted_at')
                ->where('status', UserStatus::Active->value),
            'users as invited_users_count' => fn (Builder $users) => $users
                ->whereNull('deleted_at')
                ->where('status', UserStatus::Invited->value),
        ]);
    }

    private static function loadForResponse(Organization $organization): Organization
    {
        return self::withMemberCounts(Organization::query())
            ->with('logo')
            ->whereKey($organization->getKey())
            ->firstOrFail();
    }
}
