<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Modules\ModuleReach;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleReachTest extends TestCase
{
    #[Test]
    public function a_module_switched_on_nowhere_says_so_in_the_plural(): void
    {
        $this->assertSame('0 organisaties', ModuleReach::describe(0, 4));
    }

    #[Test]
    public function one_organization_is_singular(): void
    {
        // The column is read a row at a time by somebody scanning it, and "1 organisaties" is the
        // kind of thing they would report as a bug.
        $this->assertSame('1 organisatie', ModuleReach::describe(1, 4));
    }

    #[Test]
    public function several_organizations_are_counted(): void
    {
        $this->assertSame('3 organisaties', ModuleReach::describe(3, 4));
    }

    #[Test]
    public function reaching_every_organization_reads_as_global(): void
    {
        $this->assertSame(ModuleReach::EVERYWHERE, ModuleReach::describe(4, 4));
    }

    #[Test]
    public function a_platform_with_no_organizations_is_not_global(): void
    {
        // Otherwise every module on an empty database would claim to reach everybody, which is the
        // opposite of what an operator would read into the word.
        $this->assertFalse(ModuleReach::reachesEverywhere(0, 0));
        $this->assertSame('0 organisaties', ModuleReach::describe(0, 0));
    }

    #[Test]
    public function a_new_organization_takes_a_module_back_out_of_global(): void
    {
        // "Globaal" is computed from the counts rather than stored when the form was saved, so a
        // module switched on for everybody yesterday stops being global the moment there is
        // somebody new it was not switched on for.
        $this->assertTrue(ModuleReach::reachesEverywhere(4, 4));
        $this->assertFalse(ModuleReach::reachesEverywhere(4, 5));
    }
}
