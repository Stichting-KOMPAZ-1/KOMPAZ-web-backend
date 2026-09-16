<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Models\User;

/** A signed-in session: the bearer token, and the profile it belongs to. */
final readonly class AuthenticationResult
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
