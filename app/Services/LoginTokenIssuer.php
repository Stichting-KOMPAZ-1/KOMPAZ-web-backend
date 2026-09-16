<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Issues the single-use secret behind a sign-in link and retires any link previously sent to the
 * same user for the same reason.
 */
final readonly class LoginTokenIssuer
{
    public function __construct(private SecretTokenFactory $tokenFactory) {}

    /**
     * Returns the secret to email. Only its hash is written.
     */
    public function issue(User $user, LoginTokenPurpose $purpose): string
    {
        $now = Carbon::now();

        // Retiring the previous link is a conditional UPDATE rather than a read followed by a
        // write, so two requests arriving together cannot each conclude that the other's link does
        // not exist yet and leave two live links behind.
        //
        // Only links of the same purpose are retired. An invitation is issued by an administrator,
        // while a magic link can be asked for by anybody who knows the address, so letting the
        // second retire the first would let a stranger invalidate a pending invitation as often as
        // they liked.
        LoginToken::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now]);

        $secret = $this->tokenFactory->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => $purpose,
            'expires_at' => $now->copy()->add($this->lifetimeFor($purpose)),
        ]);

        return $secret->value;
    }

    private function lifetimeFor(LoginTokenPurpose $purpose): \DateInterval
    {
        return $purpose === LoginTokenPurpose::Invitation
            ? new \DateInterval('P'.(int) config('kompaz.authentication.invitation_lifetime_days').'D')
            : new \DateInterval('PT'.(int) config('kompaz.authentication.magic_link_lifetime_minutes').'M');
    }
}
