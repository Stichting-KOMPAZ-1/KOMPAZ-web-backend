<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What is actually on a step: text, a picture or a video, in whatever order and combination an
 * operator built.
 *
 * One table for the three kinds rather than three, because they are read as one ordered list and
 * splitting them would make "the fourth block" a question that has to be answered by merging. The
 * columns a kind does not use are null, and what each kind does require is stated as a constraint
 * rather than left to the form: a text block with no body is not a block with an empty body, it is
 * a row that should never have been written.
 *
 * The title is optional for all three — the wireframes mark it so, and a picture that needs no
 * caption is the common case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('step_id')->constrained()->cascadeOnDelete();

            // Which of the three this is. Stored by name, like every other enum here.
            $table->string('type', 40);

            $table->string('title', 200)->nullable();

            // Text only, and markup: written in the panel's editor and cleaned against the
            // allowlist in SanitizedHtml on its way in, so what is stored here is HTML and what
            // reads it renders it as such.
            $table->text('body')->nullable();

            // Picture and video only. The same three columns every stored file here carries.
            $table->string('file_storage_key', 512)->nullable();
            $table->string('file_content_type', 100)->nullable();
            $table->unsignedInteger('file_byte_count')->nullable();

            // Video only, and only when it was linked rather than uploaded.
            $table->string('video_url', 2048)->nullable();

            $table->unsignedInteger('position');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['step_id', 'position']);
        });

        // What each kind must have, and what it must not. Written here as well as in the form
        // because a block is read back by the front end and assembled into a page: a picture block
        // with no picture would be a gap on somebody's screen with nothing to explain it.
        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_text_has_body
                    CHECK (type <> 'Text' OR (body IS NOT NULL AND file_storage_key IS NULL AND video_url IS NULL)),
                ADD CONSTRAINT content_blocks_image_has_file
                    CHECK (type <> 'Image' OR (file_storage_key IS NOT NULL AND body IS NULL AND video_url IS NULL)),
                ADD CONSTRAINT content_blocks_video_has_source
                    CHECK (type <> 'Video' OR (((file_storage_key IS NULL) <> (video_url IS NULL)) AND body IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blocks');
    }
};
