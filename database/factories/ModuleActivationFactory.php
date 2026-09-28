<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModuleActivation> */
final class ModuleActivationFactory extends Factory
{
    protected $model = ModuleActivation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'module_id' => Module::factory(),
            'organization_id' => Organization::factory(),
            'activated_at' => now(),
        ];
    }

    /** Switched on for an organization that already exists. */
    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
    }

    /** Switched on for a module that already exists. */
    public function ofModule(Module $module): self
    {
        return $this->state(fn (): array => ['module_id' => $module->getKey()]);
    }
}
