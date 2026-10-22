<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A carousel is one block holding its slides (KOM-100, as reviewed), not one block per slide.
 *
 * A "Stap" is one block per step, so other blocks can go between steps; a carousel is the other way
 * round — the reader pages through its slides in one place — and the first version had built it
 * like a step. So a `Carousel` block now holds nothing itself, and its slides are rows of
 * `carousel_slides`: a title, a body it may have, and a picture it may not be without, which is
 * three columns that are not null together (rule 23).
 *
 * What was already written is kept: each run of `CarouselSlide` blocks next to each other on a part
 * — which is what made a carousel until now — becomes one `Carousel` block in the place of its
 * first slide, holding those slides in their order with their pictures. The old rows are then
 * deleted by query, which no model event sees, on purpose: their pictures now belong to the slides,
 * and a discard would delete them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carousel_slides', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('content_block_id')->constrained()->cascadeOnDelete();

            $table->string('title', 200);

            // Markup, cleaned against the allowlist in SanitizedHtml on its way in.
            $table->text('body')->nullable();

            // A slide is the picture with words beside it, so the picture is not optional.
            $table->string('file_storage_key', 512);
            $table->string('file_content_type', 100);
            $table->unsignedInteger('file_byte_count');

            $table->unsignedInteger('position');

            $table->timestamp('created_at', 6);
            $table->uuid('created_by')->nullable();
            $table->timestamp('updated_at', 6);
            $table->uuid('updated_by')->nullable();

            $table->index(['content_block_id', 'position']);
        });

        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_carousel_slide_has_a_picture');

        DB::transaction(function (): void {
            $slides = DB::table('content_blocks')
                ->where('type', 'CarouselSlide')
                ->orderBy('step_id')
                ->orderBy('position')
                ->get();

            foreach ($slides->groupBy('step_id') as $stepId => $onPart) {
                $types = DB::table('content_blocks')->where('step_id', $stepId)->pluck('type', 'position');
                $carousel = null;
                $previous = null;

                foreach ($onPart as $slide) {
                    // A new carousel unless the block right before this one was a slide of the same run.
                    $continues = $carousel !== null && $this->onlySlidesBetween($types, $previous, $slide->position);

                    if (! $continues) {
                        $carousel = ['id' => (string) Str::uuid7(), 'count' => 0];

                        DB::table('content_blocks')->insert([
                            'id' => $carousel['id'],
                            'step_id' => $stepId,
                            'type' => 'Carousel',
                            'position' => $slide->position,
                            'created_at' => $slide->created_at,
                            'created_by' => $slide->created_by,
                            'updated_at' => $slide->updated_at,
                            'updated_by' => $slide->updated_by,
                        ]);
                    }

                    DB::table('carousel_slides')->insert([
                        'id' => (string) Str::uuid7(),
                        'content_block_id' => $carousel['id'],
                        'title' => $slide->title ?? 'Slide',
                        'body' => $slide->body,
                        'file_storage_key' => $slide->file_storage_key,
                        'file_content_type' => $slide->file_content_type,
                        'file_byte_count' => $slide->file_byte_count,
                        'position' => $carousel['count']++,
                        'created_at' => $slide->created_at,
                        'created_by' => $slide->created_by,
                        'updated_at' => $slide->updated_at,
                        'updated_by' => $slide->updated_by,
                    ]);

                    $previous = $slide->position;
                }
            }

            DB::table('content_blocks')->where('type', 'CarouselSlide')->delete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE content_blocks
                ADD CONSTRAINT content_blocks_carousel_holds_nothing
                    CHECK (type <> 'Carousel' OR (
                        body IS NULL AND file_storage_key IS NULL AND video_url IS NULL
                        AND table_content IS NULL AND checkbox_options IS NULL
                    ))
        SQL);
    }

    /** Back to a block per slide, each in its carousel's place and in its order. */
    public function down(): void
    {
        DB::statement('ALTER TABLE content_blocks DROP CHECK content_blocks_carousel_holds_nothing');

        DB::transaction(function (): void {
            // Last first, so moving the blocks after one carousel along never moves one that has
            // still to be unpacked out from under the position it was read at.
            $carousels = DB::table('content_blocks')
                ->where('type', 'Carousel')
                ->orderBy('step_id')
                ->orderByDesc('position')
                ->get();

            foreach ($carousels as $carousel) {
                $slides = DB::table('carousel_slides')->where('content_block_id', $carousel->id)->orderBy('position')->get();

                DB::table('content_blocks')
                    ->where('step_id', $carousel->step_id)
                    ->where('position', '>', $carousel->position)
                    ->increment('position', max($slides->count() - 1, 0));

                foreach ($slides->values() as $offset => $slide) {
                    DB::table('content_blocks')->insert([
                        'id' => (string) Str::uuid7(),
                        'step_id' => $carousel->step_id,
                        'type' => 'CarouselSlide',
                        'title' => $slide->title,
                        'body' => $slide->body,
                        'file_storage_key' => $slide->file_storage_key,
                        'file_content_type' => $slide->file_content_type,
                        'file_byte_count' => $slide->file_byte_count,
                        'position' => $carousel->position + $offset,
                        'created_at' => $slide->created_at,
                        'created_by' => $slide->created_by,
                        'updated_at' => $slide->updated_at,
                        'updated_by' => $slide->updated_by,
                    ]);
                }

                DB::table('content_blocks')->where('id', $carousel->id)->delete();
            }
        });

        Schema::dropIfExists('carousel_slides');

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

    /**
     * Whether every block between two positions on a part is a slide — so the slide at `$to`
     * continues the carousel the slide at `$from` was in. Positions may have gaps; a gap is nothing.
     *
     * @param  Collection<int|string, mixed>  $types  each block's type by its position
     */
    private function onlySlidesBetween(Collection $types, ?int $from, int $to): bool
    {
        if ($from === null) {
            return false;
        }

        foreach ($types as $position => $type) {
            if ((int) $position > $from && (int) $position < $to && $type !== 'CarouselSlide') {
                return false;
            }
        }

        return true;
    }
};
