<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ELearning;
use App\Support\Files\StoredFile;
use App\Support\Images\LogoImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ELearning> */
final class ELearningFactory extends Factory
{
    protected $model = ELearning::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $id = (string) Str::orderedUuid();

        return [
            'id' => $id,
            'name' => $this->faker->sentence(3),
            'image_storage_key' => StoredFile::mintKey(ELearning::IMAGE_PREFIX, $id, 'image', 'png'),
            'image_content_type' => LogoImage::PNG,
            'image_byte_count' => 1024,
        ];
    }
}
