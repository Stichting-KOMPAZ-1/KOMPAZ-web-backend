<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Turns a single-use secret into the address a recipient clicks.
 *
 * A link goes back to where it was asked for. A magic link requested through the API comes from
 * the product's own login page, so it lands there: the frontend hands the secret to
 * `POST /api/auth/tokens` and the recipient ends up signed in to the application they were using.
 *
 * Invitations and the panel's own link land on `nova.sign-in.claim` on this application instead.
 * Spending an invitation is what moves an invitee from invited to active, and that route both does
 * so and answers in Dutch when a week-old link no longer works.
 */
final class SignInLink
{
    /** The frontend's sign-in page, which redeems the secret for a session through the API. */
    public static function frontend(string $token): string
    {
        return rtrim((string) config('kompaz.frontend_url'), '/').'/inloggen?token='.rawurlencode($token);
    }

    /** This application's claim route, which spends the secret and opens a session on the panel. */
    public static function panel(string $token): string
    {
        return route('nova.sign-in.claim', ['token' => $token]);
    }
}
