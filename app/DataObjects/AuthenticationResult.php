<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Models\User;

/**
 * A signed-in session: a short-lived bearer token, the refresh token that renews it, and the
 * profile they belong to.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public AccessToken $accessToken,
        public RefreshTokenGrant $refreshToken,
        public User $user,
    ) {}
}
