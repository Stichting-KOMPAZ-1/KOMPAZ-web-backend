<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A seventh kind of block: one slide of a carousel (KOM-100).
 *
 * No new column and no new table. A slide is a title, a body it may have and a picture — which is
 * what a "Stap" block already holds — so it is the same row under its own type. One block per
 * slide, as the review settled for steps, so other blocks can sit between two carousels; which
 * slides belong together is their being next to each other, which the front end reads off the
 * order and nothing stores.
 *
 * Rule 23: what this kind holds is stated on the table. The one way it differs from a step is the
 * picture — a step may be without one, a slide may not, because a slide is the picture with words
 * beside it. The three file columns are still only ever true together.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_carousel_slide_has_a_picture
                    CHECK (type <> 'CarouselSlide' OR (
                        title IS NOT NULL
                        AND file_storage_key IS NOT NULL
                        AND file_content_type IS NOT NULL
                        AND file_byte_count IS NOT NULL
                        AND video_url IS NULL AND table_content IS NULL AND checkbox_options IS NULL
                    ))
        SQL);
    }

    public function down(): void
    {
        DB::table('content_blocks')->where('type', 'CarouselSlide')->delete();

        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_carousel_slide_has_a_picture');
    }
};
