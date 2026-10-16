<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A page on the frontend to land on after signing in: a path, and only ever one on this site.
 *
 * The login page passes on where the visitor was going — a shared e-learning, say — and the
 * emailed link carries it back so they land there rather than on the home page. That makes it
 * something a stranger can put into an email this application sends, so it is held to a path and
 * nothing else: it starts with a single `/`, and `//` or `/\` at the start — which a browser reads
 * as another host — is refused, as is anything with a space or a control character in it.
 */
final readonly class SameSitePath implements ValidationRule
{
    public const int MAXIMUM_LENGTH = 512;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isSameSitePath($value)) {
            $fail('De pagina om naar terug te keren is ongeldig.');
        }
    }

    public static function isSameSitePath(string $path): bool
    {
        return strlen($path) <= self::MAXIMUM_LENGTH
            && preg_match('~^/(?![/\\\\])[^\s\x00-\x1f\x7f\\\\]*$~', $path) === 1;
    }
}
