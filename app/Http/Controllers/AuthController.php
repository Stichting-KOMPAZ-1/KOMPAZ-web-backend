<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\RedeemLoginTokenAction;
use App\Actions\Authentication\RequestMagicLinkAction;
use App\Http\Requests\RedeemLoginTokenRequest;
use App\Http\Requests\RequestMagicLinkRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Passwordless sign-in: request a link by email, then exchange the link's secret for an API token.
 */
final readonly class AuthController
{
    /** Emails a sign-in link. Always accepted, whether or not the address belongs to an account. */
    public function requestMagicLink(RequestMagicLinkRequest $request, RequestMagicLinkAction $action): Response
    {
        $action->execute((string) $request->validated('email'));

        return response()->noContent(HttpResponse::HTTP_ACCEPTED);
    }

    /**
     * Exchanges the single-use secret from a sign-in link for a bearer token, activating an invited
     * user.
     */
    public function redeem(RedeemLoginTokenRequest $request, RedeemLoginTokenAction $action): JsonResponse
    {
        return AuthenticationResource::make(
            $action->execute((string) $request->validated('token')),
        )->response();
    }

    /**
     * Swaps the token on this request for a fresh one, restarting its lifetime.
     *
     * Authenticated, unlike the sign-in endpoints: the credential being renewed is the one the
     * request carries, so a client whose token has already expired signs in again rather than
     * refreshing.
     */
    public function refresh(Request $request, AuthenticationTokenService $tokens): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return AuthenticationResource::make($tokens->rotate($user))->response();
    }

    /** Signs out, on this device and every other. */
    public function revoke(Request $request, AuthenticationTokenService $tokens): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tokens->revokeAll($user);

        return response()->noContent();
    }

    /** Returns the profile behind the bearer token on this request. */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user->loadMissing(['organization', 'outstandingInvitations']))->response();
    }
}
