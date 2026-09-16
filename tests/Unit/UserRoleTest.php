<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserRoleTest extends TestCase
{
    #[Test]
    public function roles_are_hierarchical(): void
    {
        $this->assertTrue(UserRole::PlatformAdministrator->atLeast(UserRole::Administrator));
        $this->assertTrue(UserRole::Administrator->atLeast(UserRole::Member));
        $this->assertTrue(UserRole::Member->atLeast(UserRole::Member));

        $this->assertFalse(UserRole::Member->atLeast(UserRole::Administrator));
        $this->assertFalse(UserRole::Administrator->atLeast(UserRole::PlatformAdministrator));
    }

    #[Test]
    public function is_below_is_the_strict_opposite(): void
    {
        $this->assertTrue(UserRole::Member->isBelow(UserRole::Administrator));
        $this->assertFalse(UserRole::Member->isBelow(UserRole::Member));
    }

    #[Test]
    public function the_stored_values_are_the_names_the_api_uses(): void
    {
        // These strings are in tokens, in the database, and in every JSON response, so changing
        // one is a migration and a frontend change rather than a rename.
        $this->assertSame(['Member', 'Administrator', 'PlatformAdministrator'], UserRole::values());
    }
}
