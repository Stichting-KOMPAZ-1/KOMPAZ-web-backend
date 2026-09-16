<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\AuthenticationResult;
use App\Models\User;

/**
 * Issues the API tokens a signed-in client carries.
 *
 * Sanctum stores only a hash of each token, so a leaked database gives nobody a working
 * credential, and a token is revoked by deleting its row — which is what makes deleting or
 * demoting somebody take effect at once rather than at the end of an access token's life.
 */
final class AuthenticationTokenService
{
    private const string TOKEN_NAME = 'api-token';

    public function issue(User $user): AuthenticationResult
    {
        return new AuthenticationResult(
            user: $user,
            token: $user->createToken(self::TOKEN_NAME)->plainTextToken,
        );
    }

    /**
     * Swaps the token on the current request for a fresh one, which restarts its lifetime.
     *
     * The old token is deleted rather than left to expire: a client has just replaced it, so
     * anything still presenting it is not that client.
     */
    public function rotate(User $user): AuthenticationResult
    {
        $user->currentAccessToken()->delete();

        return $this->issue($user);
    }

    /** Ends every session this user has, on every device. */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
