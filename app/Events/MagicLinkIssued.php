<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sign-in link was issued for a user and needs delivering.
 *
 * Carries the secret, because only the request that issued it ever holds the plaintext — the
 * database keeps a hash. It never reaches a log: the listener passes it to the mailer and nothing
 * else.
 *
 * Dispatched after the transaction commits, so a reaction never holds a database transaction open
 * across network I/O, and so a reaction can fail after the data is safely committed. Anything that
 * raises one must therefore be safe to repeat.
 */
final readonly class MagicLinkIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $email,
        public string $name,
        public string $token,
    ) {}
}
