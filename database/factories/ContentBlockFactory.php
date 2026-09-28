<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentBlockType;
use App\Models\ContentBlock;
use App\Models\Step;
use App\Support\Files\StoredFile;
use App\Support\Images\LogoImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContentBlock> */
final class ContentBlockFactory extends Factory
{
    protected $model = ContentBlock::class;

    /**
     * A text block, which is the one kind that needs no file.
     *
     * Each state below sets every column the check constraints care about, rather than only the
     * ones it adds: a picture block that kept a body from the default would be refused by the
     * database, and the failure would read as a bug in the test rather than in the state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'step_id' => Step::factory(),
            'type' => ContentBlockType::Text,
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'position' => 0,
        ];
    }

    /** In a step that already exists, at a given place in its order. */
    public function of(Step $step, int $position = 0): self
    {
        return $this->state(fn (): array => [
            'step_id' => $step->getKey(),
            'position' => $position,
        ]);
    }

    /** A picture. */
    public function image(): self
    {
        return $this->state(fn (): array => [
            'type' => ContentBlockType::Image,
            'body' => null,
            'video_url' => null,
            'file_storage_key' => StoredFile::mintKey(
                ContentBlock::FILE_PREFIX,
                (string) Str::orderedUuid(),
                'image',
                'png',
            ),
            'file_content_type' => LogoImage::PNG,
            'file_byte_count' => 1024,
        ]);
    }

    /** A video that was uploaded. */
    public function uploadedVideo(): self
    {
        return $this->state(fn (): array => [
            'type' => ContentBlockType::Video,
            'body' => null,
            'video_url' => null,
            'file_storage_key' => StoredFile::mintKey(
                ContentBlock::FILE_PREFIX,
                (string) Str::orderedUuid(),
                'video',
                'mp4',
            ),
            'file_content_type' => 'video/mp4',
            'file_byte_count' => 2048,
        ]);
    }

    /** A video that was linked. */
    public function linkedVideo(): self
    {
        return $this->state(fn (): array => [
            'type' => ContentBlockType::Video,
            'body' => null,
            'video_url' => $this->faker->url(),
            'file_storage_key' => null,
            'file_content_type' => null,
            'file_byte_count' => null,
        ]);
    }
}
