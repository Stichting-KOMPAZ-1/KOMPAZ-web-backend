<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ModuleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModuleCategory> */
final class ModuleCategoryFactory extends Factory
{
    protected $model = ModuleCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => $name,
            'normalized_name' => ModuleCategory::normalize($name),
        ];
    }

    /** One of the categories the seeder plants, by name. */
    public function named(string $name): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'normalized_name' => ModuleCategory::normalize($name),
        ]);
    }
}
