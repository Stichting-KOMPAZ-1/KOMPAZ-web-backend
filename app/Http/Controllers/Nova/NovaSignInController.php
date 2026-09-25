<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Actions\Authentication\ClaimLoginTokenAction;
use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Exceptions\AuthenticationFailedException;
use App\Mail\NovaSignInMail;
use App\Models\User;
use App\Services\BrowserSession;
use App\Services\LoginTokenIssuer;
use App\Support\Auth\SignInLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Signing in to the admin panel.
 *
 * There are no passwords anywhere in this application, so Nova's own login form has nothing to ask
 * for. An operator gets the same kind of emailed link everybody else does — and since every link
 * this application sends is now spent here, what differs is only who this page will email one to.
 */
final readonly class NovaSignInController
{
    public function show(): View
    {
        return view('nova.sign-in');
    }

    /**
     * Emails a link, and says the same thing whether or not the address belongs to an operator.
     *
     * The panel's login page is reachable by anybody who finds the URL, so an answer that varied
     * would say which addresses are platform administrators.
     */
    public function send(Request $request, LoginTokenIssuer $issuer): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:'.User::MAXIMUM_EMAIL_LENGTH],
        ]);

        $user = User::query()
            ->where('normalized_email', User::normalize((string) $validated['email']))
            ->whereNull('deleted_at')
            ->whereIn('role', [UserRole::Administrator->value, UserRole::PlatformAdministrator->value])
            ->first();

        if ($user !== null) {
            DB::transaction(function () use ($issuer, $user): void {
                $token = $issuer->issue($user, LoginTokenPurpose::MagicLink);

                try {
                    Mail::to($user->email, $user->name)->send(
                        new NovaSignInMail($user->name, SignInLink::for($token)),
                    );
                } catch (Throwable $exception) {
                    // Swallowed because a relay failure would otherwise make this public page
                    // answer differently for an address that belongs to an operator.
                    Log::error('Failed to deliver an admin sign-in link.', [
                        'user_id' => $user->getKey(),
                        'exception' => $exception,
                    ]);
                }
            });
        }

        return redirect()
            ->route('nova.sign-in')
            ->with('status', __('nova.sign_in.sent'));
    }

    /**
     * Spends the secret and opens a session on the panel.
     *
     * Every emailed link arrives here, not only the one this controller sends: an invitation and a
     * link somebody asked for themselves are the same kind of credential and are spent the same
     * way. Spending one is also what accepts an invitation, so an invitee is activated by the
     * click whether or not the panel then admits them.
     */
    public function claim(Request $request, ClaimLoginTokenAction $claim, BrowserSession $session): RedirectResponse
    {
        $token = (string) $request->query('token', '');

        try {
            $user = $claim->execute($token);
        } catch (AuthenticationFailedException $exception) {
            return redirect()
                ->route('nova.sign-in')
                ->withErrors(['email' => $exception->getMessage()]);
        }

        // Asked after the claim, not only when the link was sent: a link outlives a demotion by up
        // to thirty minutes, and an invitation by a week. Asked of the gate rather than of the
        // role because the gate is where that rule is written, and because an invitation reaches
        // this route for somebody who was never meant to get in at all. The panel would refuse
        // them on the next page anyway; refusing here means they never get a session.
        if (! Gate::forUser($user)->allows('viewNova')) {
            return redirect()
                ->route('nova.sign-in')
                ->withErrors(['email' => __('nova.sign_in.forbidden')]);
        }

        $session->open($request, $user);

        return redirect()->intended(config('nova.path', '/nova'));
    }

    public function signOut(Request $request, BrowserSession $session): RedirectResponse
    {
        $session->close($request);

        return redirect()->route('nova.sign-in');
    }
}
