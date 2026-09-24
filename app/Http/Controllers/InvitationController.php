<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\ClaimLoginTokenAction;
use App\Exceptions\AuthenticationFailedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an invitation email's button lands.
 *
 * Accepting an invitation is a click, not an API call: the recipient has no account, no token and
 * no application open, so the secret is spent here and the outcome is shown on a page. Spending it
 * is what moves them from invited to active, which is why the landing does it before it redirects
 * anywhere.
 *
 * It then sends them to a sign-in screen rather than opening a session, because the invitation and
 * the session are two different promises: the link proves the address works, signing in is what
 * they do from now on. The screen is the panel's own for as long as that is the only one this
 * deployment serves; when the product's own login page ships, this redirect is the one line that
 * changes.
 */
final readonly class InvitationController
{
    public function accept(Request $request, ClaimLoginTokenAction $claim): RedirectResponse
    {
        try {
            $claim->execute((string) $request->query('token', ''));
        } catch (AuthenticationFailedException $exception) {
            return redirect()
                ->route('nova.sign-in')
                ->withErrors(['email' => $exception->getMessage()]);
        }

        return redirect()
            ->route('nova.sign-in')
            ->with('status', __('nova.sign_in.invitation_accepted'));
    }
}
