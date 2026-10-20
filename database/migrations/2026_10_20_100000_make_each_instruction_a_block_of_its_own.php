<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A "Stap" is one block, not a block holding a list (KOM-99, as reviewed), and a checkbox block
 * may have a body and up to twenty-five options (KOM-107, as reviewed).
 *
 * The product asked for each step to be a block of its own, so that other blocks can sit between
 * two steps; the set of steps has no title of its own. So a step is now a `content_blocks` row of
 * type `Instruction`, holding what an instruction row held — a title, which it cannot be without,
 * a body and a picture it may have — and `block_instructions` goes.
 *
 * What was already written is carried over rather than dropped: every instruction becomes a block
 * in the place its list stood, in its order, keeping its picture. A step had an optional heading
 * where a block's title is required, so a step without one takes its list's title, and "Stap" when
 * there was neither. The list's own row is then deleted by query, which no model event sees — on
 * purpose: its pictures now belong to the new blocks, and a discard would delete them.
 *
 * Rule 23 again: the new kind states on the table what it holds, including that its picture is
 * three columns that are only ever true together.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_instructions_hold_nothing');

        DB::transaction(function (): void {
            $lists = DB::table('content_blocks')->where('type', 'Instructions')->get();

            foreach ($lists as $list) {
                $instructions = DB::table('block_instructions')
                    ->where('content_block_id', $list->id)
                    ->orderBy('position')
                    ->get();

                // Every block after the list moves along to make room for its steps.
                DB::table('content_blocks')
                    ->where('step_id', $list->step_id)
                    ->where('position', '>', $list->position)
                    ->increment('position', max(count($instructions) - 1, 0));

                foreach ($instructions->values() as $offset => $instruction) {
                    DB::table('content_blocks')->insert([
                        'id' => (string) Str::uuid7(),
                        'step_id' => $list->step_id,
                        'type' => 'Instruction',
                        'title' => mb_substr($instruction->title ?? $list->title ?? 'Stap', 0, 200),
                        'body' => $instruction->body,
                        'file_storage_key' => $instruction->file_storage_key,
                        'file_content_type' => $instruction->file_content_type,
                        'file_byte_count' => $instruction->file_byte_count,
                        'position' => $list->position + $offset,
                        'created_at' => $instruction->created_at,
                        'created_by' => $instruction->created_by,
                        'updated_at' => $instruction->updated_at,
                        'updated_by' => $instruction->updated_by,
                    ]);
                }

                DB::table('content_blocks')->where('id', $list->id)->delete();
            }
        });

        Schema::dropIfExists('block_instructions');

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_checkboxes_have_options
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_checkboxes_have_options
                    CHECK (type <> 'Checkboxes' OR (
                        checkbox_options IS NOT NULL
                        AND JSON_LENGTH(checkbox_options) BETWEEN 1 AND 25
                        AND file_storage_key IS NULL AND video_url IS NULL AND table_content IS NULL
                    )),
                ADD CONSTRAINT content_blocks_instruction_has_title
                    CHECK (type <> 'Instruction' OR (
                        title IS NOT NULL
                        AND video_url IS NULL AND table_content IS NULL AND checkbox_options IS NULL
                        AND (
                            (file_storage_key IS NULL AND file_content_type IS NULL AND file_byte_count IS NULL)
                            OR (file_storage_key IS NOT NULL AND file_content_type IS NOT NULL AND file_byte_count IS NOT NULL)
                        )
                    ))
        SQL);
    }

    /**
     * Back to lists: each step becomes a list of one, which is what the old shape can hold of it.
     * A body is required there, so a step without one gets its title as its text. A checkbox
     * block's body is dropped, and one with more than twenty options keeps its first twenty.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_instruction_has_title,
                DROP CHECK content_blocks_checkboxes_have_options
        SQL);

        Schema::create('block_instructions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_block_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200)->nullable();
            $table->text('body');
            $table->string('file_storage_key', 512)->nullable();
            $table->string('file_content_type', 100)->nullable();
            $table->unsignedInteger('file_byte_count')->nullable();
            $table->unsignedInteger('position');
            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();
            $table->index(['content_block_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE block_instructions
                ADD CONSTRAINT block_instructions_file_is_whole
                    CHECK (
                        (file_storage_key IS NULL AND file_content_type IS NULL AND file_byte_count IS NULL)
                        OR (file_storage_key IS NOT NULL AND file_content_type IS NOT NULL AND file_byte_count IS NOT NULL)
                    )
        SQL);

        DB::transaction(function (): void {
            foreach (DB::table('content_blocks')->where('type', 'Instruction')->get() as $step) {
                DB::table('block_instructions')->insert([
                    'id' => (string) Str::uuid7(),
                    'content_block_id' => $step->id,
                    'title' => $step->title,
                    'body' => $step->body ?? e((string) $step->title),
                    'file_storage_key' => $step->file_storage_key,
                    'file_content_type' => $step->file_content_type,
                    'file_byte_count' => $step->file_byte_count,
                    'position' => 0,
                    'created_at' => $step->created_at,
                    'created_by' => $step->created_by,
                    'updated_at' => $step->updated_at,
                    'updated_by' => $step->updated_by,
                ]);
            }

            DB::table('content_blocks')->where('type', 'Instruction')->update([
                'type' => 'Instructions',
                'title' => null,
                'body' => null,
                'file_storage_key' => null,
                'file_content_type' => null,
                'file_byte_count' => null,
            ]);

            DB::table('content_blocks')->where('type', 'Checkboxes')->update([
                'body' => null,
                'checkbox_options' => DB::raw("JSON_EXTRACT(checkbox_options, '$[0 to 19]')"),
            ]);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_checkboxes_have_options
                    CHECK (type <> 'Checkboxes' OR (
                        checkbox_options IS NOT NULL
                        AND JSON_LENGTH(checkbox_options) BETWEEN 1 AND 20
                        AND body IS NULL AND file_storage_key IS NULL AND video_url IS NULL AND table_content IS NULL
                    )),
                ADD CONSTRAINT content_blocks_instructions_hold_nothing
                    CHECK (type <> 'Instructions' OR (
                        body IS NULL AND file_storage_key IS NULL AND video_url IS NULL
                        AND table_content IS NULL AND checkbox_options IS NULL
                    ))
        SQL);
    }
};
