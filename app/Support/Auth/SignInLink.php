<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Turns a single-use secret into the address a recipient clicks.
 *
 * Every emailed link lands in the same place, whichever email carried it: an invitation, a link
 * somebody asked for themselves, and the panel's own all point at `nova.sign-in.claim` on this
 * application. Spending a secret is what moves an invitee from invited to active and what opens a
 * session, and there is one route that does both — so a link that arrives a week late is answered
 * in Dutch on a page this deployment actually serves, rather than by a browser application that is
 * not deployed yet.
 *
 * `FRONTEND_URL` is still what CORS and `sanctum.stateful` are built from. It is no longer what
 * any link points at.
 */
final class SignInLink
{
    public static function for(string $token): string
    {
        return route('nova.sign-in.claim', ['token' => $token]);
    }
}
