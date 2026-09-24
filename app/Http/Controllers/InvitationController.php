<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\ClaimLoginTokenAction;
use App\Exceptions\AuthenticationFailedException;
use App\Services\BrowserSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Where an invitation email's button lands.
 *
 * Accepting an invitation is a click, not an API call: the recipient has no account, no token and
 * no application open, so the secret is spent here and the outcome is shown on a page. Spending it
 * is what moves them from invited to active, which is why the landing does that before it redirects
 * anywhere.
 *
 * The link is itself a credential, so somebody the panel admits is signed in by it and arrives at
 * the dashboard rather than at a login form asking for the address they just proved. Whether it
 * admits them is asked of the `viewNova` gate and not of the role, because the gate is where that
 * rule is written; sending a member to the panel would only trade one working link for a 403.
 */
final readonly class InvitationController
{
    public function accept(
        Request $request,
        ClaimLoginTokenAction $claim,
        BrowserSession $session,
    ): RedirectResponse {
        try {
            $user = $claim->execute((string) $request->query('token', ''));
        } catch (AuthenticationFailedException $exception) {
            return redirect()
                ->route('nova.sign-in')
                ->withErrors(['email' => $exception->getMessage()]);
        }

        if (! Gate::forUser($user)->allows('viewNova')) {
            // Their invitation was still accepted and their account is active; this deployment
            // just has nowhere to put them yet, so say both rather than only the refusal.
            return redirect()
                ->route('nova.sign-in')
                ->with('status', __('nova.sign_in.invitation_accepted'));
        }

        $session->open($request, $user);

        return redirect()->intended(config('nova.path', '/nova'));
    }
}
