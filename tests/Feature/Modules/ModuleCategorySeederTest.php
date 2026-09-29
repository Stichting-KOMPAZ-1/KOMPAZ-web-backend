<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\ModuleCategory;
use Database\Seeders\ModuleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModuleCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_categories_a_module_can_be_filed_under_are_planted(): void
    {
        $this->seed(ModuleCategorySeeder::class);

        $this->assertSame(['Medicatie', 'Revalidatie'], array_values(ModuleCategory::options()));
    }

    #[Test]
    public function running_it_again_plants_nothing_new(): void
    {
        // It runs on every deploy, so a second pass has to be a no-op rather than a duplicate or a
        // uniqueness failure that stops the release.
        $this->seed(ModuleCategorySeeder::class);
        $this->seed(ModuleCategorySeeder::class);

        $this->assertSame(2, ModuleCategory::query()->count());
    }

    #[Test]
    public function a_category_typed_differently_is_recognized_as_the_same_one(): void
    {
        // Matched on the folded name, like every other name here.
        ModuleCategory::factory()->named('REVALIDATIE')->create();

        $this->seed(ModuleCategorySeeder::class);

        $this->assertSame(2, ModuleCategory::query()->count());
    }
}
