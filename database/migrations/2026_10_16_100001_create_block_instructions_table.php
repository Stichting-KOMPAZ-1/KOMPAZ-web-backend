<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sixth kind of block: a numbered sequence of instructions (KOM-99), the product's "Stap".
 *
 * **Its content is rows, not columns.** Every other kind fits in `content_blocks` because what it
 * holds is one of a thing; this holds a list, and each instruction carries a heading, its own text
 * and — the reason a JSON column would not do — a picture of its own. A picture is bytes on a disk
 * named by a row (rule 12), and bytes cannot be let go of from inside a JSON document: nothing
 * would know where they were (rule 24).
 *
 * `block_instructions`, not `block_steps`: a step in this code is a *part*, the thing these blocks
 * are on. The product's word for the block is "Stap"; the code's word for what is in it is an
 * instruction, so the two never have to be told apart by context.
 *
 * The instruction row carries the same three file columns every stored file here does, and the same
 * constraint that they are only ever true together. On `content_blocks` the new constraint says the
 * opposite of the other five: an instruction block fills none of the content columns itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_instructions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('content_block_id')->constrained()->cascadeOnDelete();

            // The heading above one instruction. Optional, as a block's own title is.
            $table->string('title', 200)->nullable();

            // Markup, cleaned against the allowlist in SanitizedHtml on its way in, like a text
            // block's body.
            $table->text('body');

            // The picture beside this instruction, when it has one. Optional: the wireframe draws
            // instructions with and without.
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

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_instructions_hold_nothing
                    CHECK (type <> 'Instructions' OR (
                        body IS NULL AND file_storage_key IS NULL AND video_url IS NULL
                        AND table_content IS NULL AND checkbox_options IS NULL
                    ))
        SQL);
    }

    public function down(): void
    {
        DB::table('content_blocks')->where('type', 'Instructions')->delete();

        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_instructions_hold_nothing');

        Schema::dropIfExists('block_instructions');
    }
};
