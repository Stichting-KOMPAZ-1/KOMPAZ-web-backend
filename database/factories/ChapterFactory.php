<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\ELearning;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Chapter> */
final class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'e_learning_id' => ELearning::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'is_summary' => false,
            'position' => 0,
        ];
    }

    /** In a course that already exists, at a given place in its order. */
    public function of(ELearning $eLearning, int $position = 0): self
    {
        return $this->state(fn (): array => [
            'e_learning_id' => $eLearning->getKey(),
            'position' => $position,
        ]);
    }

    /** The chapter the front end draws differently. */
    public function summary(): self
    {
        return $this->state(fn (): array => ['is_summary' => true]);
    }
}
