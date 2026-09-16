<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a user is in the invitation lifecycle.
 *
 * Deliberately separate from deletion, which is recorded by its own timestamp: the status says
 * how far somebody got, and overwriting it on delete would lose the answer a restore has to put
 * back.
 */
enum UserStatus: string
{
    /** Invited, but has not yet signed in for the first time. */
    case Invited = 'Invited';

    /** Has proven ownership of their email address and can sign in. */
    case Active = 'Active';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
