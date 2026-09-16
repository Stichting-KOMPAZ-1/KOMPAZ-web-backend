<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\RedeemLoginTokenAction;
use App\Actions\Authentication\RefreshAccessTokenAction;
use App\Actions\Authentication\RequestMagicLinkAction;
use App\Actions\Authentication\RevokeRefreshTokenAction;
use App\Http\Requests\RedeemLoginTokenRequest;
use App\Http\Requests\RefreshTokenRequest;
use App\Http\Requests\RequestMagicLinkRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Passwordless sign-in: request a link by email, then exchange the link's secret for an access
 * token.
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
     * Exchanges a refresh token for a new access token and its successor, sliding the session
     * forward. The refresh token supplied is spent by this call; replaying it ends the session.
     */
    public function refresh(RefreshTokenRequest $request, RefreshAccessTokenAction $action): JsonResponse
    {
        return AuthenticationResource::make(
            $action->execute((string) $request->validated('refreshToken')),
        )->response();
    }

    /**
     * Signs out by ending the session a refresh token belongs to. Succeeds even for a token that is
     * already gone, so a client can always clear its credentials.
     */
    public function revoke(RefreshTokenRequest $request, RevokeRefreshTokenAction $action): Response
    {
        $action->execute((string) $request->validated('refreshToken'));

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
