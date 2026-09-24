<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a person stands on the roster an operator reads.
 *
 * Not {@see UserStatus}, which is the stored column and the API's contract: that says how far
 * somebody got, and it has no word for an invitation whose link has run out — an expired link is a
 * fact about the link and not about the user, so nothing is written when it happens. The panel
 * still has to show the difference, because an expired invitation is the one that needs resending.
 *
 * The values are the words shown in the panel, which is Dutch throughout.
 */
enum RosterStatus: string
{
    /** Has proven ownership of their email address and can sign in. */
    case Active = 'Actief';

    /** Invited, with a link that can still be accepted. */
    case Invited = 'Uitgenodigd';

    /** Invited, but the link they were sent can no longer be accepted. */
    case Expired = 'Verlopen';
}
