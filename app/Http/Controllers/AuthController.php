<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\RedeemLoginTokenAction;
use App\Http\Requests\RedeemLoginTokenRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API-token sessions entered through an invitation.
 *
 * Magic-link sign-in belongs exclusively to Nova and is handled by NovaSignInController.
 */
final readonly class AuthController
{
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
     * Authenticated, unlike invitation redemption: the credential being renewed is the one the
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
