<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentBlockType;
use App\Models\BlockInstruction;
use App\Models\ContentBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlockInstruction> */
final class BlockInstructionFactory extends Factory
{
    protected $model = BlockInstruction::class;

    /**
     * One instruction with a heading and text and no picture, which is the kind that needs no file.
     *
     * Its block defaults to an instruction block: an instruction under a block of another kind
     * would be a row the panel never reads back.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_block_id' => ContentBlock::factory()->state(['type' => ContentBlockType::Instructions, 'body' => null]),
            'title' => $this->faker->sentence(2),
            'body' => $this->faker->paragraph(),
            'position' => 0,
        ];
    }

    /** An instruction with a picture of its own, for the cascade the pictures ride on. */
    public function withImage(): self
    {
        return $this->state(fn (): array => [
            'file_storage_key' => BlockInstruction::FILE_PREFIX.'/'.$this->faker->uuid().'.png',
            'file_content_type' => 'image/png',
            'file_byte_count' => 2048,
        ]);
    }
}
