<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentBlockType;
use App\Models\CarouselSlide;
use App\Models\ContentBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarouselSlide> */
final class CarouselSlideFactory extends Factory
{
    protected $model = CarouselSlide::class;

    /**
     * A slide with its picture, which it cannot be without, under a "Carrousel" block: a slide
     * under a block of another kind would be a row nothing reads back.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_block_id' => ContentBlock::factory()->state(['type' => ContentBlockType::Carousel, 'title' => null, 'body' => null]),
            'title' => $this->faker->sentence(3),
            'body' => null,
            'file_storage_key' => CarouselSlide::FILE_PREFIX.'/'.$this->faker->uuid().'.png',
            'file_content_type' => 'image/png',
            'file_byte_count' => 2048,
            'position' => 0,
        ];
    }
}
