<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fifth kind of block: a list of options the reader ticks off (KOM-107).
 *
 * One JSON column, a list of labels, for the reason the table block has one: how many options a
 * block has is the operator's to choose, and they are only ever read and written whole. Nothing is
 * stored about a box being ticked — the block is the question, and this platform keeps no reader to
 * answer it.
 *
 * What a block of each kind must have and must not is a check constraint (rule 23), so the four
 * existing ones are restated to say a set of options is none of theirs, and the new one says a
 * checkbox block has options, at least one and at most twenty, and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->json('checkbox_options')->nullable()->after('table_content');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_text_has_body,
                DROP CHECK content_blocks_image_has_file,
                DROP CHECK content_blocks_video_has_source,
                DROP CHECK content_blocks_table_has_table
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_text_has_body
                    CHECK (type <> 'Text' OR (body IS NOT NULL AND file_storage_key IS NULL AND video_url IS NULL AND table_content IS NULL AND checkbox_options IS NULL)),
                ADD CONSTRAINT content_blocks_image_has_file
                    CHECK (type <> 'Image' OR (file_storage_key IS NOT NULL AND body IS NULL AND video_url IS NULL AND table_content IS NULL AND checkbox_options IS NULL)),
                ADD CONSTRAINT content_blocks_video_has_source
                    CHECK (type <> 'Video' OR (((file_storage_key IS NULL) <> (video_url IS NULL)) AND body IS NULL AND table_content IS NULL AND checkbox_options IS NULL)),
                ADD CONSTRAINT content_blocks_table_has_table
                    CHECK (type <> 'Table' OR (
                        table_content IS NOT NULL
                        AND JSON_LENGTH(table_content, '$.columns') BETWEEN 1 AND 10
                        AND body IS NULL AND file_storage_key IS NULL AND video_url IS NULL AND checkbox_options IS NULL
                    )),
                ADD CONSTRAINT content_blocks_checkboxes_have_options
                    CHECK (type <> 'Checkboxes' OR (
                        checkbox_options IS NOT NULL
                        AND JSON_LENGTH(checkbox_options) BETWEEN 1 AND 20
                        AND body IS NULL AND file_storage_key IS NULL AND video_url IS NULL AND table_content IS NULL
                    ))
        SQL);
    }

    public function down(): void
    {
        DB::table('content_blocks')->where('type', 'Checkboxes')->delete();

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                DROP CHECK content_blocks_checkboxes_have_options,
                DROP CHECK content_blocks_text_has_body,
                DROP CHECK content_blocks_image_has_file,
                DROP CHECK content_blocks_video_has_source,
                DROP CHECK content_blocks_table_has_table
        SQL);

        Schema::table('content_blocks', function (Blueprint $table): void {
            $table->dropColumn('checkbox_options');
        });

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
};
