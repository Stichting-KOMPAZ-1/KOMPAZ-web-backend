<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Support\Files\StoredFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two rules a video row obeys: it has exactly one owner, and it is a link or a file.
 *
 * Both are check constraints rather than model code, so the tests write straight to the table —
 * the point is that the database refuses these rows, not that a model remembered to. A form will
 * refuse them first and in Dutch; this is what holds when something goes around the form.
 */
final class ModuleVideoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_video_cannot_belong_to_a_module_and_an_activation_at_once(): void
    {
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->create();

        $this->expectException(QueryException::class);

        DB::table('module_videos')->insert($this->row([
            'module_id' => $module->getKey(),
            'module_activation_id' => $activation->getKey(),
            'url' => 'https://example.test/video',
        ]));
    }

    #[Test]
    public function a_video_owned_by_nobody_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('module_videos')->insert($this->row([
            'url' => 'https://example.test/video',
        ]));
    }

    #[Test]
    public function a_video_that_is_both_a_link_and_a_file_is_refused(): void
    {
        $module = Module::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('module_videos')->insert($this->row([
            'module_id' => $module->getKey(),
            'url' => 'https://example.test/video',
            'file_storage_key' => 'module-videos/x/video.mp4',
            'file_content_type' => 'video/mp4',
            'file_byte_count' => 10,
        ]));
    }

    #[Test]
    public function a_video_that_is_neither_a_link_nor_a_file_is_refused(): void
    {
        $module = Module::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('module_videos')->insert($this->row([
            'module_id' => $module->getKey(),
        ]));
    }

    #[Test]
    public function an_uploaded_video_and_a_linked_one_are_both_accepted(): void
    {
        // The other side of those refusals: what the constraints allow is exactly these two
        // shapes, on either kind of owner.
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->create();

        ModuleVideo::factory()->ofModule($module)->create();
        ModuleVideo::factory()->ofModule($module)->uploaded()->create();
        ModuleVideo::factory()->ofActivation($activation)->create();

        $this->assertDatabaseCount('module_videos', 3);
    }

    #[Test]
    public function applying_a_file_clears_the_link_it_replaces(): void
    {
        // Without this the save would trip the constraint, and an operator swapping a link for an
        // upload would be shown a database error for something they did correctly.
        $module = Module::factory()->create();
        $video = ModuleVideo::factory()->ofModule($module)->create();

        $video->applyFile(new StoredFile('module-videos/x/video.mp4', 'video/mp4', 10));
        $video->save();

        $this->assertNull($video->fresh()?->url);
        $this->assertFalse($video->fresh()?->isLinked());
    }

    #[Test]
    public function applying_a_link_clears_the_file_it_replaces(): void
    {
        $module = Module::factory()->create();
        $video = ModuleVideo::factory()->ofModule($module)->uploaded()->create();

        $video->applyVideoUrl('https://example.test/video');
        $video->save();

        $this->assertNull($video->fresh()?->file());
        $this->assertTrue($video->fresh()?->isLinked());
    }

    /**
     * A row with everything the table requires, plus whatever the test is actually about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'id' => (string) Str::orderedUuid(),
            'title' => 'Een video',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
