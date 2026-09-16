<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Middleware\EnsureAccountMatchesToken;
use App\Models\User;
use App\Services\AccessTokenIssuer;
use App\Support\Auth\TokenIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Authenticates a request from the bearer token it carries.
 *
 * The token is the credential and the row is the account. Both are kept: the row is what policies,
 * handlers and Nova work with, and the claims are what
 * {@see EnsureAccountMatchesToken} compares it against. A deleted user
 * resolves to nobody at all, so their remaining hour of token validity buys them nothing.
 */
final class JwtGuard implements Guard
{
    private ?User $user = null;

    private ?TokenIdentity $identity = null;

    private bool $resolved = false;

    public function __construct(
        private readonly AccessTokenIssuer $issuer,
        private readonly Request $request,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $identity = $this->issuer->parse($token);

        if ($identity === null) {
            return null;
        }

        $user = User::query()
            ->whereKey($identity->id)
            ->whereNull('deleted_at')
            ->first();

        if ($user === null) {
            return null;
        }

        $this->identity = $identity;

        return $this->user = $user;
    }

    /** What the token on this request claims, once it has been accepted. */
    public function identity(): ?TokenIdentity
    {
        $this->user();

        return $this->identity;
    }

    public function id(): ?string
    {
        return $this->user()?->getKey();
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        /** @var User $user */
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }
}
