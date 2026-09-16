<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A long-lived credential that buys a new access token without another trip through the inbox.
 *
 * Expiry slides: every use issues a successor whose window restarts from that moment, so an active
 * client stays signed in indefinitely while an idle one lapses. `absolute_expires_at` is the
 * ceiling the slide can never pass, so a session still has a hard end.
 *
 * Tokens rotate on every use. A successor keeps the `session_id` of the token it replaced, which
 * is what lets a replayed token revoke the whole chain rather than just itself.
 *
 * @property string $id
 * @property string $user_id
 * @property string $session_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon $absolute_expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
class RefreshToken extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'session_id',
        'token_hash',
        'expires_at',
        'absolute_expires_at',
        'consumed_at',
        'revoked_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the token has already been spent or withdrawn. Presenting one of these is a replay:
     * the secret leaked, or a client is retrying, and either way the session can no longer be
     * trusted.
     */
    public function isSpent(): bool
    {
        return $this->consumed_at !== null || $this->revoked_at !== null;
    }

    public function isRedeemable(Carbon $now): bool
    {
        return ! $this->isSpent() && $this->expires_at->greaterThan($now);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
