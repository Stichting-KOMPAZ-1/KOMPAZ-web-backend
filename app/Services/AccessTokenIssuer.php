<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\AccessToken;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\KompazClaims;
use App\Support\Auth\TokenIdentity;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issues and reads the signed JSON Web Tokens that carry the identity, tenant and role a request
 * is authorized against.
 */
final class AccessTokenIssuer
{
    private const string ALGORITHM = 'HS256';

    public function issue(User $user): AccessToken
    {
        $issuedAt = Carbon::now();
        $expiresAt = $issuedAt->copy()->addMinutes($this->lifetimeMinutes());

        $claims = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'iat' => $issuedAt->getTimestamp(),
            'nbf' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'jti' => (string) Str::uuid(),
            KompazClaims::SUBJECT => $user->getKey(),
            KompazClaims::EMAIL => $user->email,
            KompazClaims::NAME => $user->name,
            KompazClaims::ORGANIZATION => $user->organization_id,
            KompazClaims::ROLE => $user->role->value,
        ];

        return new AccessToken(JWT::encode($claims, $this->signingKey(), self::ALGORITHM), $expiresAt);
    }

    /**
     * Reads a bearer token, or returns null for one this application did not sign, has expired, or
     * does not carry the claims every token of its own carries.
     *
     * Every rejection is the same answer on purpose. Which of the checks failed is of interest to
     * an attacker and to nobody else.
     */
    public function parse(string $token): ?TokenIdentity
    {
        try {
            $claims = (array) JWT::decode($token, new Key($this->signingKey(), self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }

        if (($claims['iss'] ?? null) !== $this->issuer() || ($claims['aud'] ?? null) !== $this->audience()) {
            return null;
        }

        $role = UserRole::tryFrom((string) ($claims[KompazClaims::ROLE] ?? ''));
        $subject = (string) ($claims[KompazClaims::SUBJECT] ?? '');
        $organizationId = (string) ($claims[KompazClaims::ORGANIZATION] ?? '');

        if ($role === null || $subject === '' || $organizationId === '') {
            return null;
        }

        return new TokenIdentity(
            id: $subject,
            email: (string) ($claims[KompazClaims::EMAIL] ?? ''),
            name: (string) ($claims[KompazClaims::NAME] ?? ''),
            organizationId: $organizationId,
            role: $role,
        );
    }

    private function signingKey(): string
    {
        $key = (string) config('kompaz.authentication.signing_key');

        if (strlen($key) < 32) {
            // Reached only where the startup check was skipped, which is the test suite. Anywhere
            // else the application has already refused to boot.
            throw new \RuntimeException('Authentication signing key must be at least 32 bytes.');
        }

        return $key;
    }

    private function issuer(): string
    {
        return (string) config('kompaz.authentication.issuer');
    }

    private function audience(): string
    {
        return (string) config('kompaz.authentication.audience');
    }

    private function lifetimeMinutes(): int
    {
        return (int) config('kompaz.authentication.access_token_lifetime_minutes');
    }
}
