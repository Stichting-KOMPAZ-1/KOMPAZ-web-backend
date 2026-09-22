<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * Opening and closing the cookie half of a sign-in.
 *
 * Two things sign somebody in through a browser — the API, for the frontend application, and the
 * panel — and both have to do the same two steps in the same order, so they are written once here
 * rather than twice. A session that is opened without regenerating its identifier is one an
 * attacker could have planted before the visitor ever signed in.
 *
 * Everything this does is conditional on there being a session at all. A mobile or server client
 * reaches the same API endpoints without one, because only a host named in `sanctum.stateful` is
 * put through Sanctum's session middleware.
 */
final readonly class BrowserSession
{
    public function __construct(private AuthFactory $auth) {}

    /** Whether this request is the kind that can hold a session, rather than a bearer token. */
    public function isStateful(Request $request): bool
    {
        return $request->hasSession();
    }

    /**
     * Whether the caller was authenticated by its cookie rather than by a bearer token.
     *
     * Sanctum consults the session guard before it reads an Authorization header, so this is the
     * question — not whether a header was sent. A request carrying both is a session.
     */
    public function authenticatedByCookie(Request $request): bool
    {
        return $this->isStateful($request) && $this->auth->guard('web')->check();
    }

    public function open(Request $request, User $user): void
    {
        if (! $this->isStateful($request)) {
            return;
        }

        $this->auth->guard('web')->login($user);
        $request->session()->regenerate();
    }

    public function close(Request $request): void
    {
        if (! $this->isStateful($request)) {
            return;
        }

        $this->auth->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Every guard, not only the one just logged out. `auth:sanctum` makes Sanctum's guard the
        // default for the rest of the request, and it has cached whoever it resolved — so the
        // session row written after the response would still be stamped with their identifier, and
        // a row that names its owner is exactly what revoking a session looks for.
        $this->auth->forgetGuards();
    }
}
