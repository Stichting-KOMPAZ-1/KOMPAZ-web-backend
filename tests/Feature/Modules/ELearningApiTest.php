<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\UserRole;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The course API.
 *
 * A course carries no tenancy of its own — it is reached through the modules that show it — so the
 * question every test here circles is whether permission granted for one thing can be spent on
 * another: a course you were never given, a step from somebody else's course, a block from another
 * step.
 */
final class ELearningApiTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    #[Test]
    public function a_course_answers_with_its_whole_table_of_contents(): void
    {
        // The sidebar of a step page shows every chapter and every step name, so asking for it a
        // chapter at a time would be a request per heading.
        $member = $this->memberGiven($course = ELearning::factory()->create(['name' => 'Medicijnen prikken']));

        $second = Chapter::factory()->of($course, 1)->create(['name' => 'Hoofdstuk 2']);
        $first = Chapter::factory()->of($course, 0)->create(['name' => 'Hoofdstuk 1']);
        Step::factory()->of($first, 0)->create(['name' => 'Stap 1']);
        Chapter::factory()->of($course, 2)->summary()->create(['name' => 'Samenvatting']);

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertOk();

        $response->assertJsonPath('name', 'Medicijnen prikken');
        $response->assertJsonPath('chapters.0.name', 'Hoofdstuk 1');
        $response->assertJsonPath('chapters.1.name', $second->name);
        $response->assertJsonPath('chapters.0.steps.0.name', 'Stap 1');
        $response->assertJsonPath('chapters.2.isSummary', true);
        $response->assertJsonPath('chapters.0.isSummary', false);
    }

    #[Test]
    public function the_table_of_contents_carries_no_step_content(): void
    {
        // Otherwise the first request would be the entire course.
        $member = $this->memberGiven($course = ELearning::factory()->create());
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create();
        ContentBlock::factory()->of($step)->create(['body' => 'Dit hoort hier niet te staan']);

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertOk()
            ->assertJsonMissing(['body' => 'Dit hoort hier niet te staan']);
    }

    #[Test]
    public function a_course_nobody_gave_me_is_not_found(): void
    {
        $member = User::factory()->create();
        $course = ELearning::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_course_attached_to_a_module_i_lost_access_to_is_not_found(): void
    {
        // The course is reachable only through modules, so taking the module away takes the course
        // with it — with nothing to update on the course itself.
        $member = $this->memberGiven($course = ELearning::factory()->create());

        ModuleActivation::query()->delete();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_course_attached_to_nothing_is_the_platforms_alone(): void
    {
        $orphan = ELearning::factory()->create();
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$orphan->getKey()}")
            ->assertNotFound();

        $this->withHeaders($this->tokenHeaders($this->platformAdministrator()))
            ->getJson("/api/e-learnings/{$orphan->getKey()}")
            ->assertOk();
    }

    #[Test]
    public function a_step_answers_with_every_block_on_it_in_order(): void
    {
        $member = $this->memberGiven($course = ELearning::factory()->create());
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create(['name' => 'Stap 1']);

        ContentBlock::factory()->of($step, 1)->image()->create(['title' => 'Een afbeelding']);
        ContentBlock::factory()->of($step, 0)->create(['title' => 'Wat tekst', 'body' => 'De inhoud']);
        ContentBlock::factory()->of($step, 2)->linkedVideo()->create(['video_url' => 'https://example.test/v']);

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}/steps/{$step->getKey()}")
            ->assertOk();

        $response->assertJsonPath('name', 'Stap 1');
        $response->assertJsonPath('blocks.0.type', 'Text');
        $response->assertJsonPath('blocks.0.body', 'De inhoud');
        $response->assertJsonPath('blocks.0.fileUrl', null);
        $response->assertJsonPath('blocks.1.type', 'Image');
        $response->assertJsonPath('blocks.2.type', 'Video');
        $response->assertJsonPath('blocks.2.videoUrl', 'https://example.test/v');
        $response->assertJsonPath('blocks.2.fileUrl', null);
    }

    #[Test]
    public function a_step_from_another_course_is_not_reachable_through_mine(): void
    {
        // The course in the address is where permission comes from, so one readable course must
        // not be a key to every step on the platform.
        $member = $this->memberGiven($mine = ELearning::factory()->create());

        $elsewhere = Step::factory()->of(Chapter::factory()->of(ELearning::factory()->create())->create())->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$mine->getKey()}/steps/{$elsewhere->getKey()}")
            ->assertNotFound()
            ->assertJsonPath('detail', 'Dit onderdeel hoort niet bij deze e-learning.');
    }

    #[Test]
    public function a_block_from_another_step_is_not_reachable_through_mine(): void
    {
        $member = $this->memberGiven($course = ELearning::factory()->create());
        $chapter = Chapter::factory()->of($course)->create();
        $mine = Step::factory()->of($chapter, 0)->create();
        $sibling = Step::factory()->of($chapter, 1)->create();

        $elsewhere = ContentBlock::factory()->of($sibling)->image()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/e-learnings/{$course->getKey()}/steps/{$mine->getKey()}/blocks/{$elsewhere->getKey()}/file")
            ->assertNotFound()
            ->assertJsonPath('detail', 'Dit blok hoort niet bij dit onderdeel.');
    }

    #[Test]
    public function a_picture_block_serves_its_bytes(): void
    {
        $member = $this->memberGiven($course = ELearning::factory()->create());
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create();
        $block = ContentBlock::factory()->of($step)->image()->create();

        Storage::put((string) $block->file_storage_key, self::PNG);

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}/steps/{$step->getKey()}")
            ->assertOk();

        $fileUrl = $response->json('blocks.0.fileUrl');
        $this->assertIsString($fileUrl);

        $this->withHeaders($this->tokenHeaders($member))
            ->get($fileUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function a_text_block_has_no_file_to_serve(): void
    {
        $member = $this->memberGiven($course = ELearning::factory()->create());
        $chapter = Chapter::factory()->of($course)->create();
        $step = Step::factory()->of($chapter)->create();
        $block = ContentBlock::factory()->of($step)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/e-learnings/{$course->getKey()}/steps/{$step->getKey()}/blocks/{$block->getKey()}/file")
            ->assertNotFound();
    }

    #[Test]
    public function a_courses_picture_is_served_under_its_own_address(): void
    {
        $member = $this->memberGiven($course = ELearning::factory()->create());

        Storage::put($course->image_storage_key, self::PNG);

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/e-learnings/{$course->getKey()}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function the_course_endpoints_need_a_token(): void
    {
        $course = ELearning::factory()->create();

        $this->getJson("/api/e-learnings/{$course->getKey()}")->assertUnauthorized();
        $this->get("/api/e-learnings/{$course->getKey()}/image")->assertUnauthorized();
    }

    /** A member of an organization that has been given a module showing the course. */
    private function memberGiven(ELearning $course): User
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();

        $module->eLearnings()->attach($course);
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        return $member;
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
