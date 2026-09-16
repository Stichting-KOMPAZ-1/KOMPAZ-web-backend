<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invitation link was issued for a user and needs delivering. Carries the secret for the same
 * reason {@see MagicLinkIssued} does.
 */
final readonly class InvitationIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $email,
        public string $name,
        public string $organizationName,
        public string $token,
    ) {}
}
