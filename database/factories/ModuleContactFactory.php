<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModuleContact> */
final class ModuleContactFactory extends Factory
{
    protected $model = ModuleContact::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'module_activation_id' => ModuleActivation::factory(),
            'name' => $this->faker->name(),
            'job_role' => 'Verpleegkundige',
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('+3120#######'),
            'reason' => $this->faker->sentence(),
            'availability' => 'Ma t/m vr 08:00-17:00',
            'position' => 0,
        ];
    }

    /** On an activation that already exists. */
    public function ofActivation(ModuleActivation $activation): self
    {
        return $this->state(fn (): array => ['module_activation_id' => $activation->getKey()]);
    }
}
