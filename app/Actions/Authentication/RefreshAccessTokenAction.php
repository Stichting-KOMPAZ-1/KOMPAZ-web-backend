<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\DataObjects\AuthenticationResult;
use App\Exceptions\AuthenticationFailedException;
use App\Models\RefreshToken;
use App\Services\AccessTokenIssuer;
use App\Services\RefreshTokenIssuer;
use App\Services\SecretTokenFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Exchanges a refresh token for a new access token and a successor, sliding the session forward.
 */
final readonly class RefreshAccessTokenAction
{
    public function __construct(
        private SecretTokenFactory $tokenFactory,
        private AccessTokenIssuer $accessTokens,
        private RefreshTokenIssuer $refreshTokens,
    ) {}

    public function execute(string $token): AuthenticationResult
    {
        $tokenHash = $this->tokenFactory->hash($token);
        $now = Carbon::now();

        $stored = RefreshToken::query()
            ->with('user.organization')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($stored === null) {
            throw new AuthenticationFailedException('Dit vernieuwingstoken is niet geldig.');
        }

        if ($stored->isSpent()) {
            throw $this->endSessionAsReplayed($stored, $now);
        }

        if (! $stored->isRedeemable($now)) {
            throw new AuthenticationFailedException('Dit vernieuwingstoken is verlopen.');
        }

        // The checks above cannot settle it on their own: concurrent requests carrying the same
        // secret would both pass them and both rotate. Spending the token is therefore a
        // conditional UPDATE, and losing it is a replay like any other.
        //
        // Expiry is deliberately not one of the conditions below, unlike on the login-token path.
        // Losing this UPDATE revokes the whole chain, and a token that expired in the moment
        // between the check and the UPDATE would then be punished as a replay rather than reported
        // as expired. Expiry has no race worth closing: it only ever becomes more true.
        //
        // Spending and replacing happen inside one transaction so they land together. A request
        // that loses the race revokes the whole chain, and it must not be able to do that in the
        // gap between the two, or it would revoke a chain the successor has not joined yet and
        // leave it working.
        $outcome = DB::transaction(function () use ($stored, $tokenHash, $now): ?AuthenticationResult {
            $claimed = RefreshToken::query()
                ->where('token_hash', $tokenHash)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['consumed_at' => $now]);

            if ($claimed === 0) {
                return null;
            }

            $successor = $this->refreshTokens->rotate($stored);

            $user = $stored->user;
            $user->last_login_at = $now;
            $user->save();

            return new AuthenticationResult($this->accessTokens->issue($user), $successor, $user);
        });

        if ($outcome === null) {
            throw $this->endSessionAsReplayed($stored, $now);
        }

        return $outcome;
    }

    /**
     * Ends the session a replayed token belongs to and returns the failure for the caller to throw.
     *
     * Presenting a token that is already spent, and losing the race to spend one, are the same
     * event: the secret is in more than one pair of hands.
     */
    private function endSessionAsReplayed(RefreshToken $stored, Carbon $now): AuthenticationFailedException
    {
        // Withdraws every token in the chain. A replay means the secret is loose, so the whole
        // session goes, not just the token that was presented.
        RefreshToken::query()
            ->where('session_id', $stored->session_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        Log::warning('A spent refresh token was replayed; the session has been revoked.', [
            'user_id' => $stored->user_id,
            'session_id' => $stored->session_id,
        ]);

        return new AuthenticationFailedException('Dit vernieuwingstoken is al gebruikt. De sessie is beëindigd.');
    }
}
