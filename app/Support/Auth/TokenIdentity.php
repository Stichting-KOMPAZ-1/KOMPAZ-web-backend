<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAccountMatchesToken;

/**
 * What the access token on this request says about its bearer.
 *
 * Deliberately separate from the user row. A token is a signed statement about who somebody was
 * when it was issued, and nothing about it changes when the account does — which is exactly why
 * {@see EnsureAccountMatchesToken} has something to compare against.
 */
final readonly class TokenIdentity
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public string $organizationId,
        public UserRole $role,
    ) {}
}
