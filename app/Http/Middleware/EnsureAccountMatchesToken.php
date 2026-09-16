<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\JwtGuard;
use App\Exceptions\AuthenticationFailedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose caller no longer has the role or the organization their token claims.
 *
 * An access token is a signed claim about who somebody was when it was issued, and nothing about
 * it changes when the account does. Deleting a user removes their refresh tokens, so they cannot
 * start a new hour — but the hour they are already in would otherwise run to the end, and every
 * authorization decision here is made from the claims on the token. A deleted administrator could
 * have carried on inviting, editing and deleting people for the rest of the access-token lifetime.
 *
 * The same is true of what the token says about them. Demoting an administrator would otherwise
 * leave them administering for the rest of the hour, and moving somebody would leave them acting
 * inside the organization they just left. Comparing the two here is what makes an edit take effect
 * now.
 *
 * The rejection is a 401 rather than a 403 because the token is the thing that is wrong, not the
 * request: a client that still holds a refresh token exchanges it and comes straight back with
 * claims that match the row, so a demotion costs one round trip rather than a sign-in.
 *
 * Anything else that changes what somebody may do has to be compared here too. The claims on the
 * token will not notice on their own.
 */
final readonly class EnsureAccountMatchesToken
{
    public function __construct(private JwtGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->guard->user();
        $identity = $this->guard->identity();

        // The deleted and the unknown are both already nobody, which the guard answered by
        // resolving no user at all. Reaching here without one means this route does not require
        // authentication.
        if ($user === null || $identity === null) {
            return $next($request);
        }

        if ($user->role !== $identity->role || $user->organization_id !== $identity->organizationId) {
            throw new AuthenticationFailedException(
                'Dit token is uitgegeven voor een rol of organisatie die het account niet meer heeft.',
            );
        }

        return $next($request);
    }
}
