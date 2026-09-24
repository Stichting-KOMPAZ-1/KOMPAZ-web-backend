<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The capabilities a user has.
 *
 * Roles are hierarchical: every higher role includes the rights of the lower ones, which is
 * what {@see self::atLeast()} expresses. The backing values are the names the API has always
 * used, so a stored row and a token claim read the same as the JSON a client receives.
 */
enum UserRole: string
{
    /** Can read their own organization and the people in it. */
    case Member = 'Member';

    /** Can invite, update and remove users within their own organization, and rename it. */
    case Administrator = 'Administrator';

    /** Can manage every organization and every user in the system. */
    case PlatformAdministrator = 'PlatformAdministrator';

    /**
     * Where this role sits in the hierarchy. Only ever compared against another role's level,
     * never stored, so the numbers are free to move as long as their order does not.
     */
    public function level(): int
    {
        return match ($this) {
            self::Member => 0,
            self::Administrator => 1,
            self::PlatformAdministrator => 2,
        };
    }

    /** Whether this role includes everything $role may do. */
    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /** Whether this role is strictly below $role. */
    public function isBelow(self $role): bool
    {
        return $this->level() < $role->level();
    }

    /**
     * What this role is called in the panel.
     *
     * The backing value is the API's vocabulary and never changes; this is the product's, and is
     * the only thing an operator sees.
     */
    public function label(): string
    {
        return match ($this) {
            self::Member => 'Zorgprofessional',
            self::Administrator => 'Organisatie admin',
            self::PlatformAdministrator => 'Super Admin',
        };
    }

    /**
     * Every role as a select's options: the stored value against the name it is shown under.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
