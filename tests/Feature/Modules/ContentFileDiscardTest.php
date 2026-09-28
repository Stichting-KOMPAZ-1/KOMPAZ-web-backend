<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Events\ContentFileDiscarded;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\Step;
use App\Support\Files\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rule 13 for content: when a row stops pointing at bytes, the bytes go.
 *
 * The interesting cases are all cascades. A foreign key removes rows without Eloquent seeing a
 * single one of them, so a parent has to go and find its descendants' keys *before* it is deleted
 * — afterwards nothing knows where the bytes were. These tests are what keeps somebody "tidying"
 * that lookup away.
 */
final class ContentFileDiscardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_module_discards_its_picture(): void
    {
        Event::fake([ContentFileDiscarded::class]);

        $module = Module::factory()->create();

        $module->delete();

        $this->assertDiscarded((string) $module->image_storage_key);
    }

    #[Test]
    public function a_module_without_a_picture_discards_nothing(): void
    {
        Event::fake([ContentFileDiscarded::class]);

        Module::factory()->withoutImage()->create()->delete();

        Event::assertNotDispatched(ContentFileDiscarded::class);
    }

    #[Test]
    public function replacing_a_picture_discards_the_one_it_replaced(): void
    {
        // A replacement is a discard too: the row is what decides which bytes are current, so the
        // moment it names a different key the old file is unreachable.
        Event::fake([ContentFileDiscarded::class]);

        $module = Module::factory()->create();
        $original = (string) $module->image_storage_key;

        $module->applyImage(new StoredFile('modules/x/image-new.png', 'image/png', 10));
        $module->save();

        $this->assertDiscarded($original);
    }

    #[Test]
    public function taking_a_picture_away_discards_it(): void
    {
        Event::fake([ContentFileDiscarded::class]);

        $module = Module::factory()->create();
        $original = (string) $module->image_storage_key;

        $module->clearImage();
        $module->save();

        $this->assertDiscarded($original);
    }

    #[Test]
    public function deleting_a_module_discards_the_videos_its_cascade_destroys(): void
    {
        // Neither of these rows fires a model event — the foreign key takes them — so both keys
        // have to have been collected up front.
        Event::fake([ContentFileDiscarded::class]);

        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->create();

        $platformVideo = ModuleVideo::factory()->ofModule($module)->uploaded()->create();
        $organizationVideo = ModuleVideo::factory()->ofActivation($activation)->uploaded()->create();

        $module->delete();

        $this->assertDiscarded((string) $platformVideo->file_storage_key);
        $this->assertDiscarded((string) $organizationVideo->file_storage_key);
    }

    #[Test]
    public function deleting_a_course_discards_every_block_file_beneath_it(): void
    {
        // Three cascades deep: course to chapter to step to block.
        Event::fake([ContentFileDiscarded::class]);

        $course = ELearning::factory()->create();
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create();
        $block = ContentBlock::factory()->of($step)->image()->create();

        $course->delete();

        $this->assertDiscarded((string) $course->image_storage_key);
        $this->assertDiscarded((string) $block->file_storage_key);
    }

    #[Test]
    public function deleting_a_single_block_discards_only_its_own_file(): void
    {
        Event::fake([ContentFileDiscarded::class]);

        $step = Step::factory()->create();
        $block = ContentBlock::factory()->of($step)->image()->create();
        $sibling = ContentBlock::factory()->of($step, 1)->image()->create();

        $block->delete();

        $this->assertDiscarded((string) $block->file_storage_key);
        Event::assertNotDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $sibling->file_storage_key,
        );
    }

    #[Test]
    public function the_listener_removes_the_file_from_the_disk(): void
    {
        // The end of the chain, with the event real rather than faked.
        $module = Module::factory()->create();
        $key = (string) $module->image_storage_key;

        Storage::put($key, 'the bytes');
        Storage::assertExists($key);

        $module->delete();

        Storage::assertMissing($key);
    }

    private function assertDiscarded(string $key): void
    {
        Event::assertDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $key,
        );
    }
}
