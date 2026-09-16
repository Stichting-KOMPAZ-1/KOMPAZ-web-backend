<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Users\DeleteUserAction;
use App\Actions\Users\InviteUserAction;
use App\Actions\Users\ResendUserInvitationAction;
use App\Actions\Users\RestoreUserAction;
use App\Actions\Users\UpdateOwnProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\Http\Requests\IndexUsersRequest;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\UpdateOwnProfileRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\UserResource;
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

/** User management: invitations, the roster, and per-user reads and writes. */
final readonly class UserController
{
    /**
     * Returns a page of users, optionally limited to those still invited or already active.
     * Deleted users are left out unless `includeDeleted` asks for them, which is how an
     * administrator finds one to restore.
     */
    public function index(IndexUsersRequest $request): Responsable
    {
        /** @var User $actor */
        $actor = $request->user();

        $query = User::query()->with('organization');

        // Stated here rather than left to the model's soft-delete scope, for the same reason the
        // tenant boundary is stated in every handler: the queries that must see a deleted row —
        // restoring somebody, inviting an address that belongs to one — are exactly the ones a
        // filter applying itself would break quietly.
        if ($request->boolean('includeDeleted')) {
            $query->withTrashed();
        }

        $requestedOrganizationId = $request->validated('organizationId');

        if ($requestedOrganizationId !== null) {
            OrganizationAccess::ensureCanRead($actor, (string) $requestedOrganizationId);
            $query->where('organization_id', $requestedOrganizationId);
        } elseif (! OrganizationAccess::isPlatformAdministrator($actor)) {
            $query->where('organization_id', OrganizationAccess::resolveTarget($actor, null));
        }

        if (($status = $request->status()) !== null) {
            $query->where('status', $status->value);
        }

        $search = $request->validated('search');

        if (is_string($search) && trim($search) !== '') {
            $pattern = SearchPattern::contains(trim($search));

            // `normalized_email` is already folded, which is what it is for; the name has to be
            // folded here.
            $query->where(function (Builder $builder) use ($pattern): void {
                $escape = SearchPattern::ESCAPE_CHARACTER;

                $builder
                    ->whereRaw('UPPER(name) LIKE ? ESCAPE ?', [$pattern, $escape])
                    ->orWhereRaw('normalized_email LIKE ? ESCAPE ?', [$pattern, $escape]);
            });
        }

        $page = PaginatedList::create(
            $query->orderBy('name')->orderBy('id'),
            $request->pageNumber(),
            $request->pageSize(),
        );

        return new PaginatedCollection($page, UserResource::class);
    }

    /** Returns a single user. Deleted users are not found here; the roster lists them on request. */
    public function show(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        OrganizationAccess::ensureCanRead($actor, $user->organization_id);

        return UserResource::make($user->load(['organization', 'outstandingInvitations']))->response();
    }

    /**
     * Invites somebody into an organization and emails them a link that accepts the invitation and
     * signs them in.
     */
    public function invite(InviteUserRequest $request, InviteUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $user = $action->execute(
            $actor,
            (string) $request->validated('email'),
            (string) $request->validated('name'),
            $request->role(),
            $request->validated('organizationId'),
        );

        return UserResource::make($user->load(['organization', 'outstandingInvitations']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED)
            ->header('Location', '/api/users/'.$user->getKey());
    }

    /** Sends a fresh invitation link, retiring the previous one. */
    public function resendInvitation(
        Request $request,
        User $user,
        ResendUserInvitationAction $action,
    ): Response {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $user->load('organization'));

        return response()->noContent(HttpResponse::HTTP_ACCEPTED);
    }

    /** Updates the signed-in user's own profile. Open to any role, unlike the administrator edit. */
    public function updateOwnProfile(UpdateOwnProfileRequest $request, UpdateOwnProfileAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make(
            $action->execute($user, (string) $request->validated('name'))
                ->load(['organization', 'outstandingInvitations']),
        )->response();
    }

    /**
     * Updates a user's name, email address, role and organization, as an administrator. Omitting
     * `role` or `organizationId` leaves that field alone, which is the whole request an
     * organization administrator can make: changing either is reserved to platform administrators.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->execute(
            $actor,
            $user->load('organization'),
            (string) $request->validated('name'),
            (string) $request->validated('email'),
            $request->role(),
            $request->validated('organizationId'),
        );

        return UserResource::make($updated->load(['organization', 'outstandingInvitations']))->response();
    }

    /**
     * Deletes a user. They lose access immediately and are emailed about it; an administrator can
     * undo this with the restore endpoint.
     */
    public function destroy(Request $request, User $user, DeleteUserAction $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->execute($actor, $user);

        return response()->noContent();
    }

    /**
     * Restores a deleted user, leaving one who is not deleted as they are. Their sign-in links and
     * sessions are not restored with them, so they sign in again from the login page.
     */
    public function restore(Request $request, string $user, RestoreUserAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Bound by hand rather than by the route, because this is one of the two places that has
        // to reach a deleted row on purpose.
        $target = User::withTrashed()->findOrFail($user);

        return UserResource::make(
            $action->execute($actor, $target)->load(['organization', 'outstandingInvitations']),
        )->response();
    }
}
