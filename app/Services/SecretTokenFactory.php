<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\SecretToken;

/**
 * Generates opaque secrets from a cryptographic random source and hashes them for storage.
 *
 * The hash is unsalted SHA-256 on purpose: the input is 256 bits from a cryptographic source, so
 * it is not guessable and there is no dictionary to salt against. It also has to be deterministic,
 * because a secret arriving from an inbox is found by looking its hash up in a unique index.
 */
final class SecretTokenFactory
{
    private const int TOKEN_BYTES = 32;

    public function create(): SecretToken
    {
        $value = self::base64UrlEncode(random_bytes(self::TOKEN_BYTES));

        return new SecretToken($value, $this->hash($value));
    }

    public function hash(string $token): string
    {
        return strtoupper(hash('sha256', $token));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
