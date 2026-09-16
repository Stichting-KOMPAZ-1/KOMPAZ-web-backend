<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\DataObjects\AuthenticationResult;
use App\Services\AccessTokenIssuer;
use App\Services\RefreshTokenIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Exchanges the secret from a sign-in link for an access token. Redeeming a link also activates an
 * invited user.
 */
final readonly class RedeemLoginTokenAction
{
    public function __construct(
        private ClaimLoginTokenAction $claim,
        private AccessTokenIssuer $accessTokens,
        private RefreshTokenIssuer $refreshTokens,
    ) {}

    public function execute(string $token): AuthenticationResult
    {
        $user = $this->claim->execute($token);

        $refreshToken = DB::transaction(fn () => $this->refreshTokens->startSession($user));

        return new AuthenticationResult($this->accessTokens->issue($user), $refreshToken, $user);
    }
}
