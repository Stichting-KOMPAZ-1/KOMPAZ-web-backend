<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserStatus;
use App\Exceptions\AuthenticationFailedException;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spends the secret from a sign-in link and returns whose it was.
 *
 * Shared by the two things that can redeem one: the API, which hands back a token pair, and Nova,
 * which opens a session. The spending itself belongs here rather than in either of them, because
 * getting it wrong is a replay and there should only be one copy of it to get right.
 */
final readonly class ClaimLoginTokenAction
{
    public function __construct(private SecretTokenFactory $tokenFactory) {}

    public function execute(string $token): User
    {
        $tokenHash = $this->tokenFactory->hash($token);
        $now = Carbon::now();

        // Every reason to refuse — unknown, spent, expired — is one condition of a single UPDATE,
        // so the link is either claimed by this request or not claimed at all. Reading it first and
        // then spending it would let two requests carrying the same secret both pass the read and
        // both open a session.
        $claimed = LoginToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        if ($claimed === 0) {
            throw new AuthenticationFailedException('Deze inloglink is ongeldig, al gebruikt of verlopen.');
        }

        return DB::transaction(function () use ($tokenHash, $now): User {
            $loginToken = LoginToken::query()
                ->with('user.organization')
                ->where('token_hash', $tokenHash)
                ->firstOrFail();

            $user = $loginToken->user;
            $accepting = $user->status === UserStatus::Invited;

            $user->activate($now);
            $user->last_login_at = $now;
            $user->save();

            if ($accepting) {
                // The invitation has been accepted now, whichever link the invitee actually
                // arrived on — a magic link they asked for themselves activates them just as well.
                // Any invitation still outstanding is therefore spent: leaving it redeemable would
                // keep a week-long credential alive in an inbox for somebody who can already sign
                // in, where a magic link only ever lives thirty minutes, and would leave the roster
                // reporting an invitation nobody is waiting on.
                LoginToken::query()
                    ->where('user_id', $user->getKey())
                    ->where('purpose', LoginTokenPurpose::Invitation->value)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => $now]);
            }

            return $user;
        });
    }
}
