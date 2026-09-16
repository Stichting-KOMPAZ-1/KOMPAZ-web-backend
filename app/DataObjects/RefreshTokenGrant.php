<?php

declare(strict_types=1);

namespace App\DataObjects;

use Illuminate\Support\Carbon;

/** A refresh token handed to a client, and when the sliding window it opened runs out. */
final readonly class RefreshTokenGrant
{
    public function __construct(
        public string $value,
        public Carbon $expiresAt,
    ) {}
}
