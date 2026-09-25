<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * The wording a refused sign-in link is answered with, where two requests have to answer alike.
 *
 * A link is spent in exactly one place, and redeemed from two: the API, which answers JSON, and
 * this application's own pages, which answer a banner. Both are read by the person holding the
 * link, so both say the same sentence.
 */
final class AuthenticationMessages
{
    /**
     * Answers every other way a link fails — unknown, already spent, or an expired magic link.
     *
     * Deliberately one sentence for three reasons: the claim is a single conditional UPDATE that
     * cannot tell them apart, and distinguishing them would only tell somebody guessing secrets
     * which guesses were close.
     */
    public const string LINK_NOT_ACCEPTED = 'Deze inloglink is ongeldig, al gebruikt of verlopen.';

    /**
     * Answers an invitation whose week ran out.
     *
     * Separated from the sentence above because the person reading it was invited a week ago and
     * cannot fix anything by trying again: somebody else has to send them a new invitation, so the
     * message says so.
     */
    public const string INVITATION_EXPIRED = 'Deze uitnodigings-link is verlopen. Neem contact op met de beheerder om een nieuwe uitnodiging te ontvangen.';
}
