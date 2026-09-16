<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Models\RefreshToken;
use App\Services\SecretTokenFactory;
use Illuminate\Support\Carbon;

/**
 * Ends the session a refresh token belongs to.
 *
 * Signing out succeeds whether or not the token is still valid, so a client can always clear its
 * credentials without having to interpret an error.
 */
final readonly class RevokeRefreshTokenAction
{
    public function __construct(private SecretTokenFactory $tokenFactory) {}

    public function execute(string $token): void
    {
        $stored = RefreshToken::query()
            ->where('token_hash', $this->tokenFactory->hash($token))
            ->first();

        if ($stored === null) {
            return;
        }

        RefreshToken::query()
            ->where('session_id', $stored->session_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }
}
