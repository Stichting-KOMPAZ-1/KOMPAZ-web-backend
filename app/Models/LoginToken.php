<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginTokenPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use credential emailed to a user. Only the hash of the secret is stored, never the
 * value from the link.
 *
 * Written once and never updated except to be spent, so the row carries a creation time and no
 * modification time.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property LoginTokenPurpose $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
class LoginToken extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'token_hash',
        'purpose',
        'expires_at',
        'consumed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRedeemable(Carbon $now): bool
    {
        return $this->consumed_at === null && $this->expires_at->greaterThan($now);
    }

    protected function casts(): array
    {
        return [
            'purpose' => LoginTokenPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
