<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Enums\LoginTokenPurpose;
use App\Events\ContentFileDiscarded;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Services\SecretTokenFactory;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Writing a course's chapters and steps in the panel: course, then chapter, then step.
 *
 * The step form is where the work is. Its blocks are three kinds of row over one table, saved by a
 * preset of our own, and each test below is about something Nova's own preset would have got
 * wrong: the kind of a block read back, the order, a picture kept without uploading it again, and
 * the bytes of a block that was taken away.
 */
final class ELearningAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function the_chapter_and_step_forms_open(): void
    {
        // Opening a form is its own test: a field callback that cannot survive an unsaved record
        // answers 500 on the form, and posting to it would never notice.
        $this->signedInOperator();
        $step = Step::factory()->create();
        ContentBlock::factory()->of($step)->create();
        ContentBlock::factory()->of($step, 1)->image()->create();

        $this->getJson('/nova-api/chapters/creation-fields')->assertOk();
        $this->getJson("/nova-api/chapters/{$step->chapter_id}/update-fields")->assertOk();
        $this->getJson('/nova-api/steps/creation-fields')->assertOk();
        $this->getJson("/nova-api/steps/{$step->getKey()}/update-fields")->assertOk();
    }

    #[Test]
    public function a_chapter_created_from_its_course_goes_at_the_end(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        Chapter::factory()->of($course)->create();
        Chapter::factory()->of($course, 1)->create();

        $this->postJson('/nova-api/chapters?viaResource=e-learnings&viaResourceId='.$course->getKey().'&viaRelationship=chapters', [
            'eLearning' => (string) $course->getKey(),
            'name' => 'Samenvatting medicijnen onder de huid',
            'description' => 'Wat je hebt geleerd.',
            'is_summary' => true,
        ])->assertSuccessful();

        $chapter = Chapter::query()->where('name', 'Samenvatting medicijnen onder de huid')->sole();

        $this->assertSame(2, $chapter->position);
        $this->assertTrue($chapter->is_summary);
    }

    #[Test]
    public function the_courses_chapter_table_shows_steps_and_summary_in_order(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        $summary = Chapter::factory()->of($course, 1)->summary()->create(['name' => 'Samenvatting']);
        $introduction = Chapter::factory()->of($course, 0)->create(['name' => 'Introductie']);
        Step::factory()->count(3)->of($introduction)->create();

        $rows = $this->getJson('/nova-api/chapters?viaResource=e-learnings&viaResourceId='
            .$course->getKey().'&viaRelationship=chapters&relationshipType=hasMany')
            ->assertOk()
            ->json('resources');

        $this->assertIsArray($rows);
        $this->assertSame(
            [(string) $introduction->getKey(), (string) $summary->getKey()],
            array_map(static fn (array $row): string => (string) $row['id']['value'], $rows),
        );
        $this->assertSame(3, $this->fieldValue($rows[0]['fields'], 'Stappen'));
        $this->assertSame('Nee', $this->fieldValue($rows[0]['fields'], 'Samenvatting?'));
        $this->assertSame('Ja', $this->fieldValue($rows[1]['fields'], 'Samenvatting?'));
    }

    #[Test]
    public function a_step_is_created_with_its_blocks_in_the_order_they_were_placed(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();

        $this->post('/nova-api/steps', [
            'chapter' => (string) $chapter->getKey(),
            'name' => 'Stap 1: dit ga je leren',
            'blocks' => [
                $this->textRow('Welkom', 'In deze stap leer je prikken.'),
                $this->imageRow('Een spuit', UploadedFile::fake()->createWithContent('spuit.png', self::PNG)),
                $this->videoRow('Zo doe je het', 'https://www.youtube.com/watch?v=abc'),
            ],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $step = Step::query()->where('name', 'Stap 1: dit ga je leren')->sole();
        $blocks = $step->blocks;

        $this->assertSame(0, $step->position);
        $this->assertSame(
            [ContentBlockType::Text, ContentBlockType::Image, ContentBlockType::Video],
            $blocks->pluck('type')->all(),
        );
        $this->assertSame([0, 1, 2], $blocks->pluck('position')->all());
        $this->assertSame('In deze stap leer je prikken.', $blocks[0]->body);

        // Read out of the bytes and filed under the block's own key, never the upload's name.
        $this->assertSame('image/png', $blocks[1]->file_content_type);
        $this->assertStringStartsWith('content-blocks/'.$blocks[1]->getKey().'/', (string) $blocks[1]->file_storage_key);
        Storage::assertExists((string) $blocks[1]->file_storage_key);

        $this->assertSame('https://www.youtube.com/watch?v=abc', $blocks[2]->video_url);
    }

    #[Test]
    public function the_edit_form_reads_every_block_back_as_its_own_kind(): void
    {
        // Nova's own preset picks a row by model class, and all three kinds are one model: every
        // block came back as whichever kind is listed first.
        $this->signedInOperator();
        $step = Step::factory()->create();
        ContentBlock::factory()->of($step, 0)->image()->create();
        ContentBlock::factory()->of($step, 1)->linkedVideo()->create();
        ContentBlock::factory()->of($step, 2)->create();

        $fields = $this->getJson("/nova-api/steps/{$step->getKey()}/update-fields")
            ->assertOk()
            ->json('fields');

        $this->assertIsArray($fields);

        $blocks = null;

        foreach ($fields as $field) {
            if (($field['attribute'] ?? null) === 'blocks') {
                $blocks = $field;
            }
        }

        $this->assertNotNull($blocks, 'The step form has no blocks.');
        $this->assertSame(
            ['image-block-repeatable', 'video-block-repeatable', 'text-block-repeatable'],
            array_map(static fn (array $row): string => $row['type'], $blocks['value']),
        );
    }

    #[Test]
    public function an_edit_reorders_keeps_an_unchanged_picture_and_discards_a_removed_one(): void
    {
        $this->signedInOperator();
        $step = Step::factory()->create();
        $text = ContentBlock::factory()->of($step, 0)->create();
        $kept = ContentBlock::factory()->of($step, 1)->image()->create();
        $removed = ContentBlock::factory()->of($step, 2)->image()->create();

        Event::fake([ContentFileDiscarded::class]);

        $this->put("/nova-api/steps/{$step->getKey()}", [
            'chapter' => (string) $step->chapter_id,
            'name' => $step->name,
            'blocks' => [
                // The picture moved above the text, and nothing was uploaded for it.
                $this->imageRow('Nieuwe titel', null, (string) $kept->getKey()),
                $this->textRow('Tekst', 'Aangepast.', (string) $text->getKey()),
            ],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertDatabaseMissing('content_blocks', ['id' => $removed->getKey()]);

        $keptNow = $kept->fresh();
        $textNow = $text->fresh();

        $this->assertNotNull($keptNow);
        $this->assertNotNull($textNow);
        $this->assertSame(0, $keptNow->position);
        $this->assertSame($kept->file_storage_key, $keptNow->file_storage_key);
        $this->assertSame('Nieuwe titel', $keptNow->title);
        $this->assertSame(1, $textNow->position);
        $this->assertSame('Aangepast.', $textNow->body);

        Event::assertDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $removed->file_storage_key,
        );
        Event::assertNotDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $kept->file_storage_key,
        );
    }

    #[Test]
    public function a_steps_page_shows_its_blocks_in_order(): void
    {
        // Nova's repeater is form-only by default, so the step's own page showed its name and
        // nothing of what is on it.
        $this->signedInOperator();
        $step = Step::factory()->create();
        $picture = ContentBlock::factory()->of($step, 1)->image()->create(['title' => 'Een spuit']);
        ContentBlock::factory()->of($step, 0)->create(['body' => 'In deze stap leer je prikken.']);

        $fields = $this->getJson("/nova-api/steps/{$step->getKey()}")->assertOk()->json('resource.fields');

        $this->assertIsArray($fields);

        $blocks = null;

        foreach ($fields as $field) {
            if (($field['attribute'] ?? null) === 'blocks') {
                $blocks = $field['value'];
            }
        }

        $this->assertIsArray($blocks, 'The step page does not show its blocks.');
        $this->assertSame(['text-block-repeatable', 'image-block-repeatable'], array_column($blocks, 'type'));

        $shown = [];

        foreach ($blocks as $block) {
            foreach ($block['fields'] as $blockField) {
                $shown[$blockField['attribute']][] = $blockField['previewUrl'] ?? $blockField['value'];
            }
        }

        $this->assertSame(['In deze stap leer je prikken.'], $shown['body']);
        $this->assertSame(
            [route('nova.content-block-file', ['block' => (string) $picture->getKey()])],
            $shown['file_storage_key'],
        );
    }

    #[Test]
    public function a_key_from_another_step_does_not_reach_that_steps_block(): void
    {
        // The hidden key is the browser's to send. Looked up anywhere but this step's own blocks,
        // it would let one step's form rewrite — or delete — another's.
        $this->signedInOperator();
        $step = Step::factory()->create();
        ContentBlock::factory()->of($step)->create();
        $foreign = ContentBlock::factory()->create(['body' => 'Van een andere stap.']);

        $this->put("/nova-api/steps/{$step->getKey()}", [
            'chapter' => (string) $step->chapter_id,
            'name' => $step->name,
            'blocks' => [$this->textRow(null, 'Overschreven?', (string) $foreign->getKey())],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame('Van een andere stap.', $foreign->fresh()?->body);
        $this->assertSame(['Overschreven?'], $step->blocks()->pluck('body')->all());
    }

    #[Test]
    public function a_step_without_blocks_is_refused_in_the_products_words(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();

        $this->postJson('/nova-api/steps', [
            'chapter' => (string) $chapter->getKey(),
            'name' => 'Leeg',
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['blocks' => ModuleMessages::STEP_NEEDS_A_BLOCK]);

        $this->assertDatabaseCount('steps', 0);
    }

    #[Test]
    public function a_new_picture_block_without_a_picture_is_refused(): void
    {
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();

        $this->post('/nova-api/steps', [
            'chapter' => (string) $chapter->getKey(),
            'name' => 'Zonder afbeelding',
            'blocks' => [$this->imageRow('Leeg', null)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['blocks.0.fields.file_storage_key' => ModuleMessages::BLOCK_NEEDS_IMAGE]);

        $this->assertDatabaseCount('steps', 0);
    }

    #[Test]
    public function deleting_a_chapter_discards_the_files_of_every_block_under_it(): void
    {
        // The cascade removes the steps and their blocks without Eloquent seeing one of them.
        $this->signedInOperator();
        $step = Step::factory()->create();
        $block = ContentBlock::factory()->of($step)->image()->create();

        Event::fake([ContentFileDiscarded::class]);

        $this->deleteJson('/nova-api/chapters?resources[]='.$step->chapter_id)->assertOk();

        $this->assertDatabaseCount('content_blocks', 0);
        Event::assertDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $block->file_storage_key,
        );
    }

    #[Test]
    public function an_organization_administrator_cannot_read_courses_chapters_or_steps(): void
    {
        // They are let into the panel for their own organization. Refusing the listings is not
        // enough: without a policy Nova answers yes to reading one record by its key.
        $this->signedInAdministrator();
        $step = Step::factory()->create();

        $this->getJson('/nova-api/e-learnings/'.$step->chapter->e_learning_id)->assertForbidden();
        $this->getJson('/nova-api/chapters/'.$step->chapter_id)->assertForbidden();
        $this->getJson('/nova-api/steps/'.$step->getKey())->assertForbidden();
        $this->getJson('/nova-api/steps')->assertForbidden();
    }

    #[Test]
    public function a_steps_page_shows_the_whole_path_down_to_it(): void
    {
        // Nova's own breadcrumbs know one level: "Stappen", a list nobody navigates to, and then
        // the step. The wireframe asks for the course and the chapter in between.
        $this->signedInOperator();
        $course = ELearning::factory()->create(['name' => 'Medicijnen onder de huid prikken']);
        $chapter = Chapter::factory()->of($course)->create(['name' => 'Hoofdstuk 1: hoe doe je het']);
        $step = Step::factory()->of($chapter)->create(['name' => 'Stap 2: aan de slag']);

        $this->assertSame([
            ['Overzichten', null],
            ['E-learnings', '/resources/e-learnings'],
            ['Medicijnen onder de huid prikken', '/resources/e-learnings/'.$course->getKey()],
            ['Hoofdstuk 1: hoe doe je het', '/resources/chapters/'.$chapter->getKey()],
            ['Stap 2: aan de slag', null],
        ], $this->breadcrumbs('/resources/steps/'.$step->getKey()));
    }

    #[Test]
    public function creating_and_editing_inside_a_course_keep_the_path(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create(['name' => 'Cursus']);
        $chapter = Chapter::factory()->of($course)->create(['name' => 'Hoofdstuk']);

        $creating = $this->breadcrumbs('/resources/steps/new?viaResource=chapters&viaResourceId='
            .$chapter->getKey().'&viaRelationship=steps');

        $this->assertSame(['Overzichten', 'E-learnings', 'Cursus', 'Hoofdstuk', 'Stap aanmaken'], array_column($creating, 0));

        $editing = $this->breadcrumbs('/resources/chapters/'.$chapter->getKey().'/edit');

        $this->assertSame(['Overzichten', 'E-learnings', 'Cursus', 'Hoofdstuk', 'Hoofdstuk opslaan'], array_column($editing, 0));
    }

    #[Test]
    public function a_picture_blocks_thumbnail_is_read_through_the_panel(): void
    {
        // Nova's default thumbnail is the disk's public address, and the disk is private: the form
        // showed a broken image for every picture it had.
        $this->signedInOperator();
        $step = Step::factory()->create();
        $block = ContentBlock::factory()->of($step)->image()->create();

        $fields = $this->getJson("/nova-api/steps/{$step->getKey()}/update-fields")->assertOk()->json('fields');

        $this->assertIsArray($fields);

        $picture = null;

        foreach ($fields as $field) {
            if (($field['attribute'] ?? null) !== 'blocks') {
                continue;
            }

            foreach ($field['value'][0]['fields'] as $blockField) {
                if ($blockField['attribute'] === 'file_storage_key') {
                    $picture = $blockField;
                }
            }
        }

        $address = route('nova.content-block-file', ['block' => (string) $block->getKey()]);

        $this->assertIsArray($picture);
        $this->assertSame($address, $picture['thumbnailUrl']);
        $this->assertSame($address, $picture['previewUrl']);
    }

    #[Test]
    public function the_course_table_cuts_a_long_module_list_off_and_the_page_does_not(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();

        foreach (['Steunkousen Aan- en Uittrekken', 'Oogdruppels Toedienen', 'Subcutaan Injecteren'] as $name) {
            Module::factory()->create(['name' => $name])->eLearnings()->attach($course);
        }

        $index = $this->getJson('/nova-api/e-learnings')->assertOk();
        $row = $index->json('resources.0.fields');

        $this->assertIsArray($row);
        $this->assertSame(['Naam', 'Hoofdstukken', 'In module(s)'], array_column($row, 'name'));

        $inTable = $this->fieldValue($row, 'In module(s)');

        $this->assertIsString($inTable);
        $this->assertStringEndsWith('…', $inTable);

        $detail = $this->getJson('/nova-api/e-learnings/'.$course->getKey())->assertOk()->json('resource.fields');

        $this->assertIsArray($detail);
        $this->assertStringNotContainsString('…', (string) $this->fieldValue($detail, 'In module(s)'));
    }

    #[Test]
    public function a_course_can_be_linked_to_a_module_from_its_own_form(): void
    {
        // The link used to be made from the module's side only, so a course edited on its own
        // read "-" however it was meant to be used.
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        $module = Module::factory()->create(['name' => 'Subcutaan Injecteren']);

        $this->put("/nova-api/e-learnings/{$course->getKey()}", [
            'name' => $course->name,
            'modules' => (string) json_encode([['value' => (string) $module->getKey()]]),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame([(string) $course->getKey()], $module->eLearnings()->pluck('e_learnings.id')->all());

        $row = $this->getJson('/nova-api/e-learnings')->assertOk()->json('resources.0.fields');

        $this->assertIsArray($row);
        $this->assertSame('Subcutaan Injecteren', $this->fieldValue($row, 'In module(s)'));
    }

    /**
     * The breadcrumbs a Nova page is rendered with, as name and path.
     *
     * Read out of the Inertia payload in the page itself, which is what the browser receives.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function breadcrumbs(string $page): array
    {
        $html = (string) $this->get(config('nova.path').$page)->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<script data-page="app" type="application/json">(.+?)</script>#s', $html, $match));

        $props = json_decode($match[1], true);

        $this->assertIsArray($props);

        return array_map(
            static fn (array $crumb): array => [$crumb['name'], $crumb['path']],
            $props['props']['breadcrumbs']['items'] ?? $props['props']['breadcrumbs'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function fieldValue(array $fields, string $name): mixed
    {
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field['value'] ?? null;
            }
        }

        $this->fail("There is no field called {$name}.");
    }

    /** @return array<string, mixed> */
    private function textRow(?string $title, string $body, ?string $key = null): array
    {
        return ['type' => 'text-block-repeatable', 'fields' => array_filter([
            'id' => $key,
            'title' => $title,
            'body' => $body,
        ], static fn (?string $value): bool => $value !== null)];
    }

    /** @return array<string, mixed> */
    private function imageRow(?string $title, ?UploadedFile $picture, ?string $key = null): array
    {
        return ['type' => 'image-block-repeatable', 'fields' => array_filter([
            'id' => $key,
            'title' => $title,
            'file_storage_key' => $picture,
        ], static fn (string|UploadedFile|null $value): bool => $value !== null)];
    }

    /** @return array<string, mixed> */
    private function videoRow(?string $title, string $url): array
    {
        return ['type' => 'video-block-repeatable', 'fields' => array_filter([
            'title' => $title,
            'video_url' => $url,
        ], static fn (?string $value): bool => $value !== null)];
    }

    private function signedInOperator(): User
    {
        return $this->signInThroughLink(
            User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create(),
        );
    }

    private function signedInAdministrator(): User
    {
        return $this->signInThroughLink(
            User::factory()->administrator()->for(Organization::factory())->create(),
        );
    }

    private function signInThroughLink(User $user): User
    {
        config(['session.driver' => 'database']);

        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
