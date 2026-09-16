<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\DataObjects\AuthenticationResult;
use App\Services\AuthenticationTokenService;

/**
 * Exchanges the secret from a sign-in link for an API token. Redeeming a link also activates an
 * invited user.
 */
final readonly class RedeemLoginTokenAction
{
    public function __construct(
        private ClaimLoginTokenAction $claim,
        private AuthenticationTokenService $tokens,
    ) {}

    public function execute(string $token): AuthenticationResult
    {
        return $this->tokens->issue($this->claim->execute($token));
    }
}
