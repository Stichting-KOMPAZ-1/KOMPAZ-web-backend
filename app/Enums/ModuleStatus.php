<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far along a module is.
 *
 * Informative and nothing else. It was worth writing down that this is deliberate: a column called
 * "status" next to a column called "actief bij" reads like the two decide visibility together, and
 * they do not — what an organization sees is settled entirely by whether an activation row exists.
 * This is a note to the operator reading the table.
 *
 * Backed by names rather than by the Dutch words shown, for the reason {@see UserRole} is: the
 * stored value is the vocabulary the API and the database share, and the label is the product's,
 * free to be reworded without a migration.
 */
enum ModuleStatus: string
{
    /** Finished, and fit to be given to an organization's people. */
    case Available = 'Available';

    /** Still being written. Visible to whoever it is switched on for, all the same.  */
    case InDevelopment = 'InDevelopment';

    /** What this status is called in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Beschikbaar',
            self::InDevelopment => 'In ontwikkeling',
        };
    }

    /**
     * Every status as a select's options: the stored value against the name it is shown under.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
