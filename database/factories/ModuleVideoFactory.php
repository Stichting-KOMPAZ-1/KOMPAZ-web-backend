<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Support\Files\StoredFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ModuleVideo> */
final class ModuleVideoFactory extends Factory
{
    protected $model = ModuleVideo::class;

    /**
     * A linked video on a module of its own. The owner has to be stated by the caller in every
     * real case — a video belongs to a module or to an activation, and which one it is is the
     * whole question the table constrains.
     *
     * @return array<string, mixed>
     */
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

    /** The platform's own video, on a module that already exists. */
    public function ofModule(Module $module): self
    {
        return $this->state(fn (): array => [
            'module_id' => $module->getKey(),
            'module_activation_id' => null,
        ]);
    }

    /** One organization's own video, on its activation of a module. */
    public function ofActivation(ModuleActivation $activation): self
    {
        return $this->state(fn (): array => [
            'module_id' => null,
            'module_activation_id' => $activation->getKey(),
        ]);
    }

    /** Uploaded rather than linked, which is the other half of what the table allows. */
    public function uploaded(): self
    {
        return $this->state(fn (): array => [
            'url' => null,
            'file_storage_key' => StoredFile::mintKey(
                ModuleVideo::FILE_PREFIX,
                (string) Str::orderedUuid(),
                'video',
                'mp4',
            ),
            'file_content_type' => 'video/mp4',
            'file_byte_count' => 2048,
        ]);
    }
}
