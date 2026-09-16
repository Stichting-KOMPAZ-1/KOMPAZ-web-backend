<?php

declare(strict_types=1);

namespace App\Support\Organizations;

/**
 * The wording this feature answers a rejected request with, where two requests have to answer
 * alike.
 *
 * Creating an organization and renaming one break the same uniqueness rule, and a person who meets
 * it twice should not be told two different things — so the sentence lives here rather than once in
 * each place. It names no organization on purpose: the caller typed the name, and repeating it adds
 * nothing to a message that already says what to do about it.
 */
final class OrganizationMessages
{
    /** Answers a name that is already taken, folded case and all. */
    public const string NAME_TAKEN = 'Deze organisatienaam bestaat al. Geef de organisatie een unieke naam.';

    /**
     * Answers a missing name. Phrased for the form rather than for the field, because that is the
     * copy the dialog shows and a second wording would only be a second thing to keep in step.
     */
    public const string NAME_REQUIRED = 'Vul alle verplichte velden in.';

    /** Answers a name that is too long, quoting the limit the column and the validator share. */
    public static function nameTooLong(int $maximum): string
    {
        return sprintf('De organisatienaam mag maximaal %d tekens bevatten.', $maximum);
    }
}
