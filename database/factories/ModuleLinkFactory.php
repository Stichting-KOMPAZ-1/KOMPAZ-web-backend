<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModuleLink> */
final class ModuleLinkFactory extends Factory
{
    protected $model = ModuleLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'module_id' => Module::factory(),
            'module_activation_id' => null,
            'title' => $this->faker->sentence(3),
            'url' => $this->faker->url(),
            'position' => 0,
        ];
    }

    /** The platform's own link, on a module that already exists. */
    public function ofModule(Module $module): self
    {
        return $this->state(fn (): array => [
            'module_id' => $module->getKey(),
            'module_activation_id' => null,
        ]);
    }

    /** One organization's own link, on its activation of a module. */
    public function ofActivation(ModuleActivation $activation): self
    {
        return $this->state(fn (): array => [
            'module_id' => null,
            'module_activation_id' => $activation->getKey(),
        ]);
    }
}
