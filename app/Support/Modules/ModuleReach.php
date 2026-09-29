<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * How far a module reaches, in the words the panel's "Actief bij" column uses.
 *
 * One place rather than a callback in a Nova field, because the same sentence has to come out of
 * the modules table and out of any later view that answers the same question — and because
 * "Globaal" is a product rule with an edge to it, not a formatting choice. A module switched on
 * for every organization there currently is reads as global; switch on a new organization tomorrow
 * and that module is no longer active everywhere, so the word is computed from the counts each
 * time rather than stored when the form was saved.
 *
 * Both counts are the caller's to supply. Asking the database here would be one query per row of a
 * table that exists to be read a page at a time.
 */
final class ModuleReach
{
    /** What the panel shows a module that reaches everybody. */
    public const string EVERYWHERE = 'Globaal';

    /**
     * The column's text for a module with $activationCount activations, on a platform that has
     * $organizationCount organizations.
     */
    public static function describe(int $activationCount, int $organizationCount): string
    {
        if (self::reachesEverywhere($activationCount, $organizationCount)) {
            return self::EVERYWHERE;
        }

        return $activationCount === 1
            ? '1 organisatie'
            : sprintf('%d organisaties', $activationCount);
    }

    /**
     * Whether the module is switched on for every organization there is.
     *
     * A platform with no organizations at all is not "everywhere" — that reading would label every
     * module global on an empty database, which is the opposite of what an operator would take it
     * to mean.
     */
    public static function reachesEverywhere(int $activationCount, int $organizationCount): bool
    {
        return $organizationCount > 0 && $activationCount >= $organizationCount;
    }
}
