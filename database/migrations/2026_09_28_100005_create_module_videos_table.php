<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A video shown on a module — either one the platform put there, or one an organization added for
 * its own people.
 *
 * One table with two nullable owners rather than two tables or a polymorphic pair. Two tables
 * would be the same six columns twice and the same rules written twice; a polymorphic owner would
 * be a column with no foreign key behind it, so deleting a module would leave its videos standing
 * and nothing in the database would object. Two real foreign keys and a constraint saying exactly
 * one of them is set keeps the cascades and costs one line of DDL.
 *
 * A video is a link or a file, never both and never neither. That is the second constraint, and it
 * is here rather than only in the form because a row with both would make "the video" a question
 * about precedence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_videos', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Exactly one of these is set; see the constraint below.
            $table->foreignUuid('module_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('module_activation_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title', 200);

            // The link, when it is one. Long enough for the query strings a video host appends.
            $table->string('url', 2048)->nullable();

            // The upload, when it is one. Same three columns as every other stored file here: the
            // key is minted, and the media type is what the bytes turned out to be.
            $table->string('file_storage_key', 512)->nullable();
            $table->string('file_content_type', 100)->nullable();
            $table->unsignedInteger('file_byte_count')->nullable();

            // The order the entries were arranged in. Not unique: reordering a list would have to
            // shuffle through a gap to avoid colliding halfway, and a duplicate here costs a
            // stable tie-break rather than correctness.
            // Defaulted, because the panel's repeatable rows arrive without one: the key is a
            // UUIDv7 and therefore already in insertion order, so an unnumbered list keeps the
            // order it was typed in. An explicit position still takes precedence over it.
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['module_id', 'position']);
            $table->index(['module_activation_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE module_videos
                ADD CONSTRAINT module_videos_one_owner
                    CHECK ((module_id IS NULL) <> (module_activation_id IS NULL)),
                ADD CONSTRAINT module_videos_link_or_file
                    CHECK ((url IS NULL) <> (file_storage_key IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_videos');
    }
};
