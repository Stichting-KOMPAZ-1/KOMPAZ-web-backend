<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Turns a single-use secret into the address a recipient clicks.
 *
 * The two kinds of link land in different places, because they are answering different questions.
 * A magic link is asked for from the product's own login page, so it goes back there: the browser
 * exchanges the secret for a session and a link opened twice shows a page rather than a JSON error.
 *
 * An invitation goes to this application instead. Accepting an invitation is what moves somebody
 * from invited to active, and that has to happen even when the recipient has no account anywhere
 * yet and nothing but the email in front of them — so the secret is spent here, by a route that can
 * say in Dutch why a week-old link no longer works, rather than by a page that has to be deployed
 * first.
 */
final class SignInLink
{
    /** The product's own sign-in page, which exchanges the secret for a session. */
    public static function magicLink(string $token): string
    {
        $base = rtrim((string) config('kompaz.frontend_url'), '/');
        $path = '/'.ltrim((string) config('kompaz.sign_in_path'), '/');

        return $base.$path.'?token='.rawurlencode($token);
    }

    /** This application's invitation landing, which accepts the invitation and then asks for a sign-in. */
    public static function invitation(string $token): string
    {
        return route('invitation.accept', ['token' => $token]);
    }
}
