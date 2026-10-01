<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Step;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The course tree: what it takes with it when it goes, what order it comes back in, and what a
 * content block is allowed to be.
 *
 * The ordering tests matter more than they look. `position` is what the front end reads a course
 * in, so a relation that fell back to insertion order would put somebody's chapters in the order
 * they happened to be typed rather than the order an operator arranged them.
 */
final class ELearningTreeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_course_takes_its_chapters_steps_and_blocks(): void
    {
        $course = ELearning::factory()->create();
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create();
        ContentBlock::factory()->of($step)->create();

        $course->delete();

        $this->assertDatabaseCount('chapters', 0);
        $this->assertDatabaseCount('steps', 0);
        $this->assertDatabaseCount('content_blocks', 0);
    }

    #[Test]
    public function deleting_a_chapter_leaves_the_course_and_its_other_chapters(): void
    {
        $course = ELearning::factory()->create();
        $first = Chapter::factory()->of($course, 0)->create();
        $second = Chapter::factory()->of($course, 1)->create();

        $first->delete();

        $this->assertModelExists($course);
        $this->assertModelExists($second);
    }

    #[Test]
    public function a_new_chapter_and_step_go_last_even_after_a_deletion_left_a_gap(): void
    {
        // Deleting renumbers nothing. With [2, 3] left, the count is 2 — a place already taken —
        // and a new row placed there sorted before the last one instead of after it.
        $course = ELearning::factory()->create();
        Chapter::factory()->of($course, 0)->create()->delete();
        Chapter::factory()->of($course, 1)->create()->delete();
        $kept = Chapter::factory()->of($course, 2)->create();
        Chapter::factory()->of($course, 3)->create();

        $chapter = Chapter::factory()->of($course)->create(['position' => null]);

        $this->assertSame(4, $chapter->position);

        Step::factory()->of($kept, 0)->create()->delete();
        Step::factory()->of($kept, 5)->create();

        $step = Step::factory()->of($kept)->create(['position' => null]);

        $this->assertSame(6, $step->position);

        // And the first of either starts at nought.
        $this->assertSame(0, Step::factory()->of($chapter)->create(['position' => null])->position);
    }

    #[Test]
    public function the_tree_comes_back_in_the_order_it_was_arranged_in(): void
    {
        $course = ELearning::factory()->create();

        // Created out of order on purpose: insertion order and arranged order must not agree, or
        // the assertion would pass whether or not anything sorts.
        $second = Chapter::factory()->of($course, 1)->create(['name' => 'Tweede']);
        $first = Chapter::factory()->of($course, 0)->create(['name' => 'Eerste']);

        $this->assertSame(
            [$first->getKey(), $second->getKey()],
            $course->chapters()->pluck('id')->all(),
        );

        $laterStep = Step::factory()->of($first, 1)->create();
        $earlierStep = Step::factory()->of($first, 0)->create();

        $this->assertSame(
            [$earlierStep->getKey(), $laterStep->getKey()],
            $first->steps()->pluck('id')->all(),
        );

        $laterBlock = ContentBlock::factory()->of($earlierStep, 1)->create();
        $earlierBlock = ContentBlock::factory()->of($earlierStep, 0)->create();

        $this->assertSame(
            [$earlierBlock->getKey(), $laterBlock->getKey()],
            $earlierStep->blocks()->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_course_may_have_several_summary_chapters(): void
    {
        // Allowed for now by product decision, so nothing refuses the second one. Written down as
        // a test because the opposite is the more obvious reading of the word.
        $course = ELearning::factory()->create();

        Chapter::factory()->of($course, 0)->summary()->create();
        Chapter::factory()->of($course, 1)->summary()->create();

        $this->assertSame(2, $course->chapters()->where('is_summary', true)->count());
    }

    #[Test]
    public function each_kind_of_block_is_accepted_in_its_own_shape(): void
    {
        $step = Step::factory()->create();

        ContentBlock::factory()->of($step, 0)->create();
        ContentBlock::factory()->of($step, 1)->image()->create();
        ContentBlock::factory()->of($step, 2)->uploadedVideo()->create();
        ContentBlock::factory()->of($step, 3)->linkedVideo()->create();

        $this->assertDatabaseCount('content_blocks', 4);
    }

    #[Test]
    public function a_text_block_without_a_body_is_refused(): void
    {
        $step = Step::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('content_blocks')->insert($this->row($step, [
            'type' => ContentBlockType::Text->value,
        ]));
    }

    #[Test]
    public function a_picture_block_without_a_picture_is_refused(): void
    {
        // A gap on somebody's screen with nothing to explain it is worse than a save that failed.
        $step = Step::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('content_blocks')->insert($this->row($step, [
            'type' => ContentBlockType::Image->value,
            'title' => 'Zonder afbeelding',
        ]));
    }

    #[Test]
    public function a_video_block_that_is_both_uploaded_and_linked_is_refused(): void
    {
        $step = Step::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('content_blocks')->insert($this->row($step, [
            'type' => ContentBlockType::Video->value,
            'video_url' => 'https://example.test/video',
            'file_storage_key' => 'content-blocks/x/video.mp4',
            'file_content_type' => 'video/mp4',
            'file_byte_count' => 10,
        ]));
    }

    #[Test]
    public function a_block_cannot_carry_a_column_belonging_to_another_kind(): void
    {
        // The constraints say what each kind must not have as well as what it must: a picture
        // block with a body would be rendered by whichever half of the front end looked first.
        $step = Step::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('content_blocks')->insert($this->row($step, [
            'type' => ContentBlockType::Image->value,
            'body' => 'Tekst op een afbeeldingsblok',
            'file_storage_key' => 'content-blocks/x/image.png',
            'file_content_type' => 'image/png',
            'file_byte_count' => 10,
        ]));
    }

    /**
     * A row with everything the table requires, plus whatever the test is actually about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(Step $step, array $overrides): array
    {
        return array_merge([
            'id' => (string) Str::orderedUuid(),
            'step_id' => $step->getKey(),
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
