<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Authentication\RedeemLoginTokenAction;
use App\Actions\Authentication\RequestMagicLinkAction;
use App\Exceptions\ConflictException;
use App\Http\Requests\RedeemLoginTokenRequest;
use App\Http\Requests\RequestMagicLinkRequest;
use App\Http\Resources\AuthenticationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthenticationTokenService;
use App\Services\BrowserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sessions, entered through an invitation or through a sign-in link asked for from the login page.
 *
 * One sign-in, two credentials. Redeeming a link always answers with a bearer token, which is what
 * a mobile or server client keeps; a request that arrives from the browser application also leaves
 * with a session cookie, so a page can call this API without holding a token where a script could
 * read it. Which of the two a caller gets is decided by where the request came from and never by
 * anything in it — see `sanctum.stateful`.
 *
 * The panel's own link flow is still separate and lives in NovaSignInController: it opens a session
 * on Nova, points somewhere else, and admits platform administrators only.
 */
final readonly class AuthController
{
    /**
     * Exchanges the single-use secret from a sign-in link for a bearer token, activating an invited
     * user.
     */
    public function redeem(
        RedeemLoginTokenRequest $request,
        RedeemLoginTokenAction $action,
        BrowserSession $session,
    ): JsonResponse {
        $result = $action->execute((string) $request->validated('token'));

        // Only when the request carries a session at all, which is to say only for the browser
        // application. Every other caller gets the token and nothing else, exactly as before.
        $session->open($request, $result->user);

        return AuthenticationResource::make($result)->response();
    }

    /**
     * Sends a sign-in link to whoever holds an email address.
     *
     * Answers 204 whether or not anybody does. The endpoint is unauthenticated, so a reply that
     * distinguished the two would answer "does this person have an account?" for any address a
     * caller cared to try. Rate limited separately from redemption, because this half sends email.
     */
    public function requestMagicLink(RequestMagicLinkRequest $request, RequestMagicLinkAction $action): Response
    {
        $action->execute((string) $request->validated('email'));

        return response()->noContent();
    }

    /**
     * Swaps the token on this request for a fresh one, restarting its lifetime.
     *
     * Authenticated, unlike invitation redemption: the credential being renewed is the one the
     * request carries, so a client whose token has already expired signs in again rather than
     * refreshing.
     *
     * Which means a caller signed in by cookie is refused rather than served. It has no token to
     * renew, its session already renews itself on every request, and issuing one here would turn
     * this endpoint into a way to convert a session into a credential that outlives it.
     */
    public function refresh(
        Request $request,
        AuthenticationTokenService $tokens,
        BrowserSession $session,
    ): JsonResponse {
        if ($session->authenticatedByCookie($request)) {
            throw new ConflictException(
                'Deze sessie werkt met een cookie en heeft geen token dat vernieuwd kan worden.',
            );
        }

        /** @var User $user */
        $user = $request->user();

        return AuthenticationResource::make($tokens->rotate($user))->response();
    }

    /** Signs out, on this device and every other, whichever kind of credential each one holds. */
    public function revoke(
        Request $request,
        AuthenticationTokenService $tokens,
        BrowserSession $session,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        // The caller's own session goes first, which destroys its row and clears its cookie;
        // revokeAll then sweeps the tokens and whatever sessions other browsers are still holding.
        $session->close($request);

        $tokens->revokeAll($user);

        return response()->noContent();
    }

    /** Returns the profile behind whichever credential this request carries. */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user->loadMissing(['organization', 'outstandingInvitations']))->response();
    }
}
