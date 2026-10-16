<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth kind of block: a table of plain text, column headers and rows (KOM-101).
 *
 * One JSON column, `{columns: [...], rows: [[...]]}`, because a table's shape is the operator's to
 * choose per block — one to ten columns, any number of rows — and is only ever read and written
 * whole. What a block of each kind must have and must not is a check constraint, as it is for the
 * other three (rule 23), so the three existing ones are restated to say a table is none of theirs,
 * and the new one says a table block has a table, of one to ten columns, and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->json('table_content')->nullable()->after('video_url');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_text_has_body,
                DROP CHECK content_blocks_image_has_file,
                DROP CHECK content_blocks_video_has_source
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_text_has_body
                    CHECK (type <> 'Text' OR (body IS NOT NULL AND file_storage_key IS NULL AND video_url IS NULL AND table_content IS NULL)),
                ADD CONSTRAINT content_blocks_image_has_file
                    CHECK (type <> 'Image' OR (file_storage_key IS NOT NULL AND body IS NULL AND video_url IS NULL AND table_content IS NULL)),
                ADD CONSTRAINT content_blocks_video_has_source
                    CHECK (type <> 'Video' OR (((file_storage_key IS NULL) <> (video_url IS NULL)) AND body IS NULL AND table_content IS NULL)),
                ADD CONSTRAINT content_blocks_table_has_table
                    CHECK (type <> 'Table' OR (
                        table_content IS NOT NULL
                        AND JSON_LENGTH(table_content, '$.columns') BETWEEN 1 AND 10
                        AND body IS NULL AND file_storage_key IS NULL AND video_url IS NULL
                    ))
        SQL);
    }

    public function down(): void
    {
        DB::table('content_blocks')->where('type', 'Table')->delete();

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_table_has_table,
                DROP CHECK content_blocks_text_has_body,
                DROP CHECK content_blocks_image_has_file,
                DROP CHECK content_blocks_video_has_source
        SQL);

        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->dropColumn('table_content');
        });

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
};
