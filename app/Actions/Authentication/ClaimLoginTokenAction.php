<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserStatus;
use App\Exceptions\AuthenticationFailedException;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\SecretTokenFactory;
use App\Support\Auth\AuthenticationMessages;
use App\Support\Organizations\OrganizationMessages;
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
            throw new AuthenticationFailedException($this->refusalFor($tokenHash, $now));
        }

        return DB::transaction(function () use ($tokenHash, $now): User {
            $loginToken = LoginToken::query()
                ->with('user.organization')
                ->where('token_hash', $tokenHash)
                ->firstOrFail();

            $user = $loginToken->user;

            // Every link this application issues is spent here — an invitation, a magic link, and
            // the panel's own — so this is the one place that can promise nobody signs in to an
            // organization that is out of service. Archiving deletes the links and tokens that
            // existed at the time, which leaves only links issued afterwards; refusing here means
            // it does not matter which of those two a caller found.
            //
            // The secret is spent by the time this refuses, and deliberately so: the claim is what
            // makes it exclusive, and a link that survived a refusal would be one an archived
            // organization's member could keep presenting until the moment it was reopened.
            if ($user->organization->isArchived()) {
                throw new AuthenticationFailedException(OrganizationMessages::ORGANIZATION_ARCHIVED);
            }

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

    /**
     * Chooses the sentence a refused link is answered with, once the claim has already refused it.
     *
     * Reading the row here is not the check-then-write this class exists to avoid. The conditional
     * UPDATE above has already run and changed nothing, so nothing found now can make the secret
     * spendable again — this only decides what to say, and only to somebody who was holding a real
     * secret to begin with.
     *
     * An invitation that ran out of its week is the one case worth separating: it is the only
     * refusal the reader cannot do anything about themselves.
     */
    private function refusalFor(string $tokenHash, Carbon $now): string
    {
        $expiredInvitation = LoginToken::query()
            ->where('token_hash', $tokenHash)
            ->where('purpose', LoginTokenPurpose::Invitation->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '<=', $now)
            ->exists();

        return $expiredInvitation
            ? AuthenticationMessages::INVITATION_EXPIRED
            : AuthenticationMessages::LINK_NOT_ACCEPTED;
    }
}
