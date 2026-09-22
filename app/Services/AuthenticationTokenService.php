<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\AuthenticationResult;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues the API tokens a signed-in client carries, and ends the credentials a signed-in person
 * holds — of either kind.
 *
 * Sanctum stores only a hash of each token, so a leaked database gives nobody a working credential,
 * and a token is revoked by deleting its row — which is what makes deleting or demoting somebody
 * take effect at once rather than at the end of an access token's life.
 *
 * Since the browser application signs in with a cookie, a token is no longer the only way to be
 * signed in, and a session is revoked the same way: {@see SessionRegistry} deletes its row. Both
 * go together in {@see revokeAll}, because every caller of it means "this person is signed out",
 * and a caller that had to remember to say it twice would eventually only say it once.
 */
final readonly class AuthenticationTokenService
{
    private const string TOKEN_NAME = 'api-token';

    public function __construct(private SessionRegistry $sessions) {}

    public function issue(User $user): AuthenticationResult
    {
        return new AuthenticationResult(
            user: $user,
            token: $user->createToken(self::TOKEN_NAME)->plainTextToken,
        );
    }

    /**
     * Swaps the token on the current request for a fresh one, which restarts its lifetime.
     *
     * The old token is deleted rather than left to expire: a client has just replaced it, so
     * anything still presenting it is not that client.
     *
     * Only ever reached by a caller that actually carries one: Sanctum stands a session in for a
     * token with a TransientToken, which has nothing to delete, so AuthController refuses a cookie
     * caller before it gets here.
     */
    public function rotate(User $user): AuthenticationResult
    {
        $user->currentAccessToken()->delete();

        return $this->issue($user);
    }

    /** Ends every session this user has, on every device and in every browser. */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
        $this->sessions->forget($user);
    }

    /**
     * The same, for everybody in a list at once — what archiving an organization needs.
     *
     * Here rather than in the caller for the reason {@see revokeAll} is: signing somebody out means
     * both kinds of credential, and a caller holding the two halves itself is a caller that can
     * come to hold only one. Archiving was written when a token was the only half there was, and
     * kept working while quietly leaving every browser signed in.
     *
     * @param  list<string>  $userIds
     */
    public function revokeAllFor(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();

        $this->sessions->forgetMany($userIds);
    }
}
