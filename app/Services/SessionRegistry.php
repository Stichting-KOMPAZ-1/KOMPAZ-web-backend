<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Ends the cookie sessions somebody holds, wherever they are holding them.
 *
 * A Sanctum token is revoked by deleting its row, and nothing about it goes stale because the user
 * row is read on every request. A session cookie is the same kind of thing — a row in `sessions`
 * with the holder's identifier on it — so it is ended the same way, and for the same reason: there
 * is no claim to expire and no second copy to keep in step. Deleting the rows takes effect on the
 * holder's very next request, in every browser at once, which is what "sign out everywhere" has to
 * mean now that a browser is one of the things that can be signed in.
 *
 * This reads the `sessions` table directly rather than through the session store, because the
 * store only ever knows about the request it is handling. {@see AppServiceProvider} refuses to
 * start on a session driver that does not have such a table.
 */
final readonly class SessionRegistry
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    public function forget(User $user): void
    {
        $this->forgetMany([(string) $user->getKey()]);
    }

    /**
     * Ends the sessions of several people at once.
     *
     * Archiving an organization signs out everybody in it, and doing that one person at a time
     * would put two statements per member inside a single transaction. The rows are keyed by user
     * either way, so one `IN` answers the whole organization.
     *
     * @param  list<string>  $userIds
     */
    public function forgetMany(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->connections
            ->connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->whereIn('user_id', $userIds)
            ->delete();
    }
}
