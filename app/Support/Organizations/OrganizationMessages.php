<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Support\Images\LogoImage;

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

    /**
     * Answers archiving the organization that runs the platform, and archiving one the caller
     * belongs to — the second because it would take the caller's own access with it.
     */
    public const string PLATFORM_CANNOT_BE_ARCHIVED = 'De organisatie die het platform beheert kan niet worden gearchiveerd.';

    public const string CANNOT_ARCHIVE_OWN_ORGANIZATION = 'Een organisatie kan niet worden gearchiveerd door een van haar eigen leden.';

    /** Answers archiving one that is already archived, and restoring one that is not archived. */
    public const string ALREADY_ARCHIVED = 'Deze organisatie is al gearchiveerd.';

    public const string NOT_ARCHIVED = 'Deze organisatie is niet gearchiveerd.';

    /** Answers somebody signing in who belongs to an organization that is out of service. */
    public const string ORGANIZATION_ARCHIVED = 'Deze organisatie is gearchiveerd. Neem contact op met de beheerder.';

    /** Answers an upload with no file, or with something that is not one. */
    public const string LOGO_REQUIRED = 'Kies een logo om te uploaden.';

    /**
     * Answers a file that is too large, and one whose bytes are not an image this accepts.
     *
     * Neither takes the limit as an argument, unlike the name above: both are stated once on
     * {@see LogoImage}, which is also what enforces them, so quoting anything else here would be
     * inventing a second answer to the same question.
     */
    public static function logoTooLarge(): string
    {
        return sprintf('Upload een kleiner bestand van maximaal %s.', LogoImage::MAXIMUM_SIZE);
    }

    public static function logoWrongFormat(): string
    {
        return sprintf('Upload een afbeelding van het type %s.', LogoImage::ACCEPTED_FORMATS);
    }
}
