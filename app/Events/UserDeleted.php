<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An administrator deleted a user who could sign in, and who needs telling.
 *
 * Carries the address and the name as values rather than an identifier to load: the reaction runs
 * after the save, by which time the roster no longer lists this person, and a notification that
 * had to look them up would be reading a row the rest of the system now treats as gone.
 */
final readonly class UserDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $email,
        public string $name,
    ) {}
}
