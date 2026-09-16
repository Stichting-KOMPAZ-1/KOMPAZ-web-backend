<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a single-use login token was issued. Both purposes authenticate; they differ in lifetime
 * and in the email that carries them.
 */
enum LoginTokenPurpose: string
{
    /** A short-lived sign-in link requested by an existing user. */
    case MagicLink = 'MagicLink';

    /** A longer-lived link that both accepts an invitation and signs the user in. */
    case Invitation = 'Invitation';
}
