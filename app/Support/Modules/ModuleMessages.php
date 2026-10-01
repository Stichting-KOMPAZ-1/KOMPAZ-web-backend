<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * The words the product chose for modules, in one place.
 *
 * Rule 15: anything a caller or an operator reads is Dutch, and wording the product dictates lives
 * in a constant when more than one thing has to say it — or, as here, when the exact sentence was
 * written down in the ticket and a paraphrase would be a different promise. The deletion warning
 * in particular is a promise: it tells an operator what will *not* be deleted, and it is asserted
 * by a test so that the panel and the ticket cannot drift apart.
 */
final class ModuleMessages
{
    /** What an operator reads before deleting a module, word for word as the product wrote it. */
    public const string DELETE_MODULE_CONFIRMATION = 'Weet je zeker dat je deze module wilt verwijderen? '
        .'De module wordt volledig uit het systeem gehaald en zal niet zichtbaar meer zijn voor organisaties. '
        .'Herstellen is daarna niet meer mogelijk. '
        .'De e-learnings binnen deze module worden NIET verwijderd. '
        .'Deze kunnen herbruikt worden in andere modules, of los verwijderd worden.';

    /** The same, for a course. Also a promise: the modules that show it are not deleted with it. */
    public const string DELETE_E_LEARNING_CONFIRMATION = 'Weet je zeker dat je deze e-learning wilt verwijderen? '
        .'De e-learning wordt volledig uit het systeem gehaald en zal niet zichtbaar meer zijn voor organisaties. '
        .'Herstellen is daarna niet meer mogelijk. '
        .'Mogelijke gelinkte modules worden hierbij NIET verwijderd.';

    public const string E_LEARNING_DELETED = 'De e-learning is verwijderd.';

    /** What a picture block added to a step without a picture is refused with. */
    public const string BLOCK_NEEDS_IMAGE = 'Upload een afbeelding voor dit blok.';

    /** A text block with no text, as the API refuses it. A video's are in VideoMessages. */
    public const string BLOCK_NEEDS_BODY = 'Vul de tekst van dit blok in.';

    /** A module with no description. Prose now carries markup, and markup can strip to nothing. */
    public const string MODULE_NEEDS_DESCRIPTION = 'Vul een omschrijving voor de module in.';

    /** What a step with no blocks at all is refused with: the blocks are what a step is. */
    public const string STEP_NEEDS_A_BLOCK = 'Voeg minstens één blok toe.';

    /** The confirm and cancel buttons under it. */
    public const string DELETE_MODULE_CONFIRM_BUTTON = 'Verwijderen';

    public const string CANCEL_BUTTON = 'Annuleren';

    public const string MODULE_DELETED = 'De module is verwijderd.';

    /** A category's name, blank. */
    public const string CATEGORY_NAME_REQUIRED = 'Vul een naam voor de categorie in.';

    /** A category's name that another one already has, compared without regard to case. */
    public const string CATEGORY_NAME_TAKEN = 'Er bestaat al een categorie met deze naam.';

    public const string CATEGORY_CREATED = 'De categorie is aangemaakt.';

    public const string CATEGORY_RENAMED = 'De categorie is hernoemd.';

    public const string CATEGORY_DELETED = 'De categorie is verwijderd.';

    public const string DELETE_CATEGORY_CONFIRMATION = 'Weet je zeker dat je deze categorie wilt verwijderen? '
        .'Dit kan alleen als geen enkele module er nog onder valt.';

    public static function categoryNameTooLong(int $maximum): string
    {
        return sprintf('Gebruik voor de naam van de categorie maximaal %d tekens.', $maximum);
    }

    /** Why a category that modules still wear cannot go: they would be left filed under nothing. */
    public static function categoryInUse(int $modules): string
    {
        return $modules === 1
            ? 'Deze categorie wordt nog gebruikt door 1 module. Kies daar eerst een andere categorie.'
            : sprintf('Deze categorie wordt nog gebruikt door %d modules. Kies daar eerst een andere categorie.', $modules);
    }

    /** How many videos one module or one organization's copy of it may carry. */
    public static function maximumVideos(): int
    {
        return (int) config('kompaz.modules.maximum_videos');
    }

    public static function maximumLinks(): int
    {
        return (int) config('kompaz.modules.maximum_links');
    }

    public static function maximumContacts(): int
    {
        return (int) config('kompaz.modules.maximum_contacts');
    }

    /** What an operator reads when they try to add an eleventh of something. */
    public static function tooManyVideos(): string
    {
        return sprintf('Voeg maximaal %d video\'s toe.', self::maximumVideos());
    }

    public static function tooManyLinks(): string
    {
        return sprintf('Voeg maximaal %d links toe.', self::maximumLinks());
    }

    public static function tooManyContacts(): string
    {
        return sprintf('Voeg maximaal %d contactpersonen toe.', self::maximumContacts());
    }
}
