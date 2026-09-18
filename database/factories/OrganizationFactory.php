<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'normalized_name' => Organization::normalize($name),
            'is_platform' => false,
        ];
    }

    /** Out of service: nobody who belongs to it can sign in. */
    public function archived(): self
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    /** The one organization that runs the platform. */
    public function platform(): self
    {
        return $this->state(fn (): array => ['is_platform' => true]);
    }
}
