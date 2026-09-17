<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody asked for a sign-in link and it needs delivering. Carries the secret for the same reason
 * an invitation event does: only the issuing request ever holds the plaintext.
 *
 * Raised only when the address belongs to somebody who can sign in. The endpoint answers the same
 * either way, so the absence of this event is what keeps an unknown address indistinguishable from
 * a known one rather than anything the caller is told.
 */
final readonly class MagicLinkRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $email,
        public string $name,
        public string $token,
    ) {}
}
