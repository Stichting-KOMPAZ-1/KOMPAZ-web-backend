<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Step;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Step> */
final class StepFactory extends Factory
{
    protected $model = Step::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'name' => $this->faker->sentence(3),
            'position' => 0,
        ];
    }

    /** In a chapter that already exists, at a given place in its order. */
    public function of(Chapter $chapter, int $position = 0): self
    {
        return $this->state(fn (): array => [
            'chapter_id' => $chapter->getKey(),
            'position' => $position,
        ]);
    }
}
