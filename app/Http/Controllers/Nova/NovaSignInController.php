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
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Signing in to the admin panel.
 *
 * There are no passwords anywhere in this application, so Nova's own login form has nothing to ask
 * for. An operator gets the same kind of emailed link everybody else does; what differs is where
 * it points and that only a platform administrator is ever sent one.
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
            ->where('role', UserRole::PlatformAdministrator->value)
            ->first();

        if ($user !== null) {
            DB::transaction(function () use ($issuer, $user): void {
                $token = $issuer->issue($user, LoginTokenPurpose::MagicLink);

                try {
                    Mail::to($user->email, $user->name)->send(
                        new NovaSignInMail($user->name, route('nova.sign-in.claim', ['token' => $token])),
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

    /** Spends the secret and opens a session on the panel. */
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

        // Checked again after the claim, not only when the link was sent: a link is valid for
        // thirty minutes, and somebody can be demoted inside that window. The `viewNova` gate
        // would refuse them afterwards anyway; refusing here means they never get a session at all.
        if ($user->role !== UserRole::PlatformAdministrator) {
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
