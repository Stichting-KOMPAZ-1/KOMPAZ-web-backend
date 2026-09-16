<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Turns a single-use secret into the address a recipient clicks.
 *
 * The link points at the frontend, not at this API: the browser lands on a page that exchanges the
 * secret for a session, so a link opened twice shows a page rather than a JSON error.
 */
final class SignInLink
{
    public static function for(string $token): string
    {
        $base = rtrim((string) config('kompaz.frontend_url'), '/');
        $path = '/'.ltrim((string) config('kompaz.sign_in_path'), '/');

        return $base.$path.'?token='.rawurlencode($token);
    }
}
