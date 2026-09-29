<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModuleStatus;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Support\Files\StoredFile;
use App\Support\Images\LogoImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Module> */
final class ModuleFactory extends Factory
{
    protected $model = Module::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $id = (string) Str::orderedUuid();

        return [
            'id' => $id,
            'name' => $this->faker->sentence(3),
            'category_id' => ModuleCategory::factory(),
            'description' => $this->faker->paragraph(),
            'source_attribution' => null,
            'status' => ModuleStatus::Available,

            // A key that reads like a minted one, pointing at nothing. Tests that care about the
            // bytes put them on the fake disk themselves; the rest only need a row that is
            // internally consistent.
            'image_storage_key' => StoredFile::mintKey(Module::IMAGE_PREFIX, $id, 'image', 'png'),
            'image_content_type' => LogoImage::PNG,
            'image_byte_count' => 1024,
        ];
    }

    /** Still being written. */
    public function inDevelopment(): self
    {
        return $this->state(fn (): array => ['status' => ModuleStatus::InDevelopment]);
    }

    /** Filed under a category that already exists, rather than a fresh one. */
    public function inCategory(ModuleCategory $category): self
    {
        return $this->state(fn (): array => ['category_id' => $category->getKey()]);
    }

    /** No picture at all, which some modules genuinely have. All three columns, or none. */
    public function withoutImage(): self
    {
        return $this->state(fn (): array => [
            'image_storage_key' => null,
            'image_content_type' => null,
            'image_byte_count' => null,
        ]);
    }
}
