<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\RefreshTokenGrant;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Starts and continues refresh-token sessions.
 */
final readonly class RefreshTokenIssuer
{
    public function __construct(private SecretTokenFactory $tokenFactory) {}

    /**
     * Opens a new session for a user who has just proven their identity. Sessions already open
     * elsewhere are left alone, so signing in on one device does not sign the user out on another.
     */
    public function startSession(User $user): RefreshTokenGrant
    {
        $now = Carbon::now();
        $secret = $this->tokenFactory->create();
        $absoluteExpiresAt = $now->copy()->addDays($this->absoluteLifetimeDays());
        $expiresAt = self::earliest($now->copy()->addDays($this->slidingLifetimeDays()), $absoluteExpiresAt);

        RefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'session_id' => (string) Str::uuid(),
            'token_hash' => $secret->hash,
            'expires_at' => $expiresAt,
            'absolute_expires_at' => $absoluteExpiresAt,
        ]);

        return new RefreshTokenGrant($secret->value, $expiresAt);
    }

    /**
     * Opens the successor of a token that has just been spent, restarting the sliding window and
     * keeping the session it belongs to, so a later replay can revoke the whole chain.
     */
    public function rotate(RefreshToken $current): RefreshTokenGrant
    {
        $now = Carbon::now();
        $secret = $this->tokenFactory->create();
        $expiresAt = self::earliest(
            $now->copy()->addDays($this->slidingLifetimeDays()),
            $current->absolute_expires_at,
        );

        RefreshToken::query()->create([
            'user_id' => $current->user_id,
            'session_id' => $current->session_id,
            'token_hash' => $secret->hash,
            'expires_at' => $expiresAt,
            'absolute_expires_at' => $current->absolute_expires_at,
        ]);

        return new RefreshTokenGrant($secret->value, $expiresAt);
    }

    /** The sliding window, capped by the ceiling the session can never pass. */
    private static function earliest(Carbon $first, Carbon $second): Carbon
    {
        return $first->lessThan($second) ? $first : $second;
    }

    private function slidingLifetimeDays(): int
    {
        return (int) config('kompaz.authentication.refresh_token_sliding_lifetime_days');
    }

    private function absoluteLifetimeDays(): int
    {
        return (int) config('kompaz.authentication.refresh_token_absolute_lifetime_days');
    }
}
