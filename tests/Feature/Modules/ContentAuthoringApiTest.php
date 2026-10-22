<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Enums\ModuleStatus;
use App\Enums\UserRole;
use App\Events\ContentFileDiscarded;
use App\Models\CarouselSlide;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\ModuleContact;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Writing modules, courses, chapters and steps through the API, for the frontend's admin pages.
 *
 * The content was written in the panel alone until the frontend needed the same. Everything the
 * panel's forms enforce has to hold here too, and it does because both ask the same rules and the
 * same actions — so these tests are mostly about the edges a second door opens: who may write,
 * what a key sent by a client may reach, and a list left out of a body.
 */
final class ContentAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function the_platform_creates_a_module_with_its_lists_and_hands_it_out(): void
    {
        $operator = $this->platformAdministrator();
        $category = ModuleCategory::factory()->create();
        $course = ELearning::factory()->create();
        $organization = Organization::factory()->create();

        // Multipart, because a module is created with its picture.
        $response = $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/modules', $this->moduleBody($category, [
                'eLearningIds' => [(string) $course->getKey()],
                'organizationIds' => [(string) $organization->getKey()],
                'videos' => [['title' => 'Zo prik je', 'url' => 'https://www.youtube.com/watch?v=abc']],
                'links' => [['title' => 'Bijsluiter', 'url' => 'https://example.nl/bijsluiter']],
                'image' => UploadedFile::fake()->createWithContent('photo.jpg', self::PNG),
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('name', 'Subcutaan Injecteren')
            ->assertJsonPath('categoryId', (string) $category->getKey())
            ->assertJsonPath('videos.0.title', 'Zo prik je')
            ->assertJsonPath('links.0.url', 'https://example.nl/bijsluiter')
            ->assertJsonPath('eLearnings.0.id', (string) $course->getKey());

        $module = Module::query()->findOrFail($response->json('id'));

        $response->assertJsonPath('imageUrl', '/api/modules/'.$module->getKey().'/image');
        $this->assertNotNull($module->activationFor((string) $organization->getKey()));

        // Filed under the module's own folder, and recognized by its bytes rather than its name.
        $this->assertSame('image/png', $module->image_content_type);
        $this->assertStringStartsWith('modules/'.$module->getKey().'/', (string) $module->image_storage_key);
        Storage::assertExists((string) $module->image_storage_key);
    }

    #[Test]
    public function a_module_without_a_picture_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $category = ModuleCategory::factory()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson('/api/modules', $this->moduleBody($category))
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.image.0', 'afbeelding is verplicht.');

        $this->assertDatabaseCount('modules', 0);
    }

    #[Test]
    public function a_list_left_out_is_kept_and_an_empty_one_is_cleared(): void
    {
        // A client renaming a module must not unlink its courses by not mentioning them.
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $course = ELearning::factory()->create();
        $module->eLearnings()->attach($course);
        ModuleVideo::factory()->ofModule($module)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/modules/{$module->getKey()}", $this->moduleBody($module->category, ['name' => 'Nieuwe naam']))
            ->assertOk()
            ->assertJsonPath('name', 'Nieuwe naam')
            ->assertJsonCount(1, 'eLearnings')
            ->assertJsonCount(1, 'videos');

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/modules/{$module->getKey()}", $this->moduleBody($module->category, ['eLearningIds' => [], 'videos' => []]))
            ->assertOk()
            ->assertJsonCount(0, 'eLearnings')
            ->assertJsonCount(0, 'videos');
    }

    #[Test]
    public function a_video_key_from_another_module_is_a_new_video_and_that_one_is_untouched(): void
    {
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $foreign = ModuleVideo::factory()->ofModule(Module::factory()->create())->create(['title' => 'Van een ander']);

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/modules/{$module->getKey()}", $this->moduleBody($module->category, [
                'videos' => [['id' => (string) $foreign->getKey(), 'title' => 'Overschreven?', 'url' => 'https://example.nl/v']],
            ]))
            ->assertOk();

        $this->assertSame('Van een ander', $foreign->fresh()?->title);
        $this->assertSame(['Overschreven?'], $module->videos()->pluck('title')->all());
    }

    #[Test]
    public function the_api_refuses_what_the_panel_refuses_in_the_same_words(): void
    {
        $operator = $this->platformAdministrator();
        $category = ModuleCategory::factory()->create();
        $tooMany = array_fill(0, ModuleMessages::maximumVideos() + 1, ['title' => 'Video', 'url' => 'https://example.nl/v']);

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson('/api/modules', $this->moduleBody($category, ['videos' => $tooMany]))
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.videos.0', ModuleMessages::tooManyVideos());

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson('/api/modules', $this->moduleBody($category, ['name' => '']))
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.name.0', 'naam is verplicht.');

        $this->assertDatabaseCount('modules', 0);
    }

    #[Test]
    public function nobody_but_the_platform_writes_a_module(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create(['name' => 'Van het platform']);
        ModuleActivation::factory()->ofModule($module)->forOrganization($administrator->organization)->create();

        $headers = $this->tokenHeaders($administrator);

        $this->withHeaders($headers)->postJson('/api/modules', $this->moduleBody($module->category))->assertForbidden();
        $this->withHeaders($headers)->putJson("/api/modules/{$module->getKey()}", $this->moduleBody($module->category))->assertForbidden();
        $this->withHeaders($headers)->deleteJson("/api/modules/{$module->getKey()}")->assertForbidden();

        $this->assertSame('Van het platform', $module->fresh()?->name);
    }

    #[Test]
    public function a_modules_picture_is_replaced_and_never_taken_away(): void
    {
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $replaced = (string) $module->image_storage_key;

        Event::fake([ContentFileDiscarded::class]);

        // Multipart, so a POST carrying the method: PHP reads no files out of a real PUT.
        $this->withHeaders($this->tokenHeaders($operator))
            ->post("/api/modules/{$module->getKey()}/image", [
                '_method' => 'PUT',
                'image' => UploadedFile::fake()->createWithContent('photo.jpg', self::PNG),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imageUrl', '/api/modules/'.$module->getKey().'/image');

        $stored = $module->fresh();

        $this->assertNotNull($stored);
        // Read out of the bytes: the upload called itself a JPEG.
        $this->assertSame('image/png', $stored->image_content_type);
        Storage::assertExists((string) $stored->image_storage_key);

        Event::assertDispatched(
            ContentFileDiscarded::class,
            fn (ContentFileDiscarded $event): bool => $event->storageKey === $replaced,
        );

        // A module always has a picture now, so there is no address that takes it away.
        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("/api/modules/{$module->getKey()}/image")
            ->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->assertNotNull($module->fresh()?->image());
    }

    #[Test]
    public function the_platform_deletes_a_module_and_its_courses_survive(): void
    {
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $course = ELearning::factory()->create();
        $module->eLearnings()->attach($course);

        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("/api/modules/{$module->getKey()}")
            ->assertNoContent();

        $this->assertModelMissing($module);
        $this->assertModelExists($course);
    }

    #[Test]
    public function an_organization_administrator_fills_in_their_own_copy(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($administrator->organization)->create();

        $url = "/api/modules/{$module->getKey()}/organizations/{$administrator->organization_id}";

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, [
                'contacts' => [[
                    'name' => 'Petra de Vries',
                    'jobRole' => 'Wondverpleegkundige',
                    'email' => 'petra@example.nl',
                    'phone' => '0201234567',
                ]],
                'links' => [['title' => 'Ons protocol', 'url' => 'https://example.nl/protocol']],
            ])
            ->assertOk()
            ->assertJsonPath('contacts.0.name', 'Petra de Vries')
            ->assertJsonPath('contacts.0.jobRole', 'Wondverpleegkundige')
            ->assertJsonPath('contacts.0.email', 'petra@example.nl')
            ->assertJsonPath('links.0.title', 'Ons protocol');

        $this->withHeaders($this->tokenHeaders($administrator))
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('contacts.0.phone', '0201234567');

        // What a reader of the module then sees, merged with the platform's own.
        $this->withHeaders($this->tokenHeaders($administrator))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertJsonPath('contacts.0.name', 'Petra de Vries');
    }

    #[Test]
    public function another_organizations_copy_cannot_be_read_or_written(): void
    {
        // Rule 22's case: one organization writing the phone number another one shows.
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create();
        $theirs = ModuleActivation::factory()->ofModule($module)->create();
        ModuleContact::factory()->ofActivation($theirs)->create(['name' => 'Hun contactpersoon']);

        $url = "/api/modules/{$module->getKey()}/organizations/{$theirs->organization_id}";

        $this->withHeaders($this->tokenHeaders($administrator))->getJson($url)->assertForbidden();
        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['contacts' => [$this->contact('Iemand anders')]])
            ->assertForbidden();

        $this->assertSame(['Hun contactpersoon'], $theirs->contacts()->pluck('name')->all());
    }

    #[Test]
    public function a_contact_without_a_job_role_or_an_email_address_is_refused(): void
    {
        // KOM-61: the name, the job role and the e-mail address are what a card cannot do without.
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->forOrganization($administrator->organization)->create();

        $refused = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson("/api/modules/{$module->getKey()}/organizations/{$administrator->organization_id}", [
                'contacts' => [['name' => 'Petra de Vries', 'phone' => '0201234567']],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(['functie is verplicht.'], $this->errorsFor($refused, 'contacts.0.jobRole'));
        $this->assertSame(['e-mailadres is verplicht.'], $this->errorsFor($refused, 'contacts.0.email'));

        $this->assertSame(0, $activation->contacts()->count());
    }

    #[Test]
    public function an_address_typed_without_its_scheme_is_completed_and_one_that_leads_nowhere_is_explained(): void
    {
        // KOM-73. The API asks the same rule as the panel's forms, so both doors take "www.…".
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($administrator->organization)->create();
        $url = "/api/modules/{$module->getKey()}/organizations/{$administrator->organization_id}";

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['links' => [['title' => 'Ons protocol', 'url' => 'www.voorbeeld.nl/protocol']]])
            ->assertOk()
            ->assertJsonPath('links.0.url', 'https://www.voorbeeld.nl/protocol');

        $refused = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['links' => [['title' => 'Ons protocol', 'url' => 'voorbeeld']]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::INVALID_WEB_ADDRESS], $this->errorsFor($refused, 'links.0.url'));
    }

    #[Test]
    public function each_step_is_a_block_of_its_own_and_is_numbered_among_the_steps(): void
    {
        // KOM-99, through the API's door. A step is one block, so other blocks can go between two
        // of them; its number is counted from the steps before it, not sent and not stored.
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $written = $this->withHeaders($this->tokenHeaders($operator))
            ->post($base, [
                'name' => 'Zo prik je',
                'blocks' => [
                    ['type' => ContentBlockType::Instruction->value, 'title' => 'Was je handen', 'body' => '<p>Met water en zeep.</p>'],
                    ['type' => ContentBlockType::Text->value, 'body' => 'Tussendoor.'],
                    [
                        'type' => ContentBlockType::Instruction->value,
                        'title' => 'Pak de pen',
                        'image' => UploadedFile::fake()->createWithContent('pen.png', self::PNG),
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('blocks.0.type', ContentBlockType::Instruction->value)
            ->assertJsonPath('blocks.0.title', 'Was je handen')
            ->assertJsonPath('blocks.0.body', '<p>Met water en zeep.</p>')
            ->assertJsonPath('blocks.0.number', 1)
            ->assertJsonPath('blocks.0.fileUrl', null)
            ->assertJsonPath('blocks.1.number', null)
            ->assertJsonPath('blocks.2.number', 2)
            // The body is optional.
            ->assertJsonPath('blocks.2.body', null);

        $this->assertNotNull($written->json('blocks.2.fileUrl'));

        // A picture is kept unless a new one is sent, and `removeImage` takes it away.
        $partId = (string) $written->json('id');
        $blocks = [
            ['id' => $written->json('blocks.0.id'), 'type' => ContentBlockType::Instruction->value, 'title' => 'Was je handen'],
            ['id' => $written->json('blocks.2.id'), 'type' => ContentBlockType::Instruction->value, 'title' => 'Pak de pen'],
        ];

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/{$partId}", ['name' => 'Zo prik je', 'blocks' => $blocks])
            ->assertOk()
            ->assertJsonPath('blocks.1.number', 2);

        $this->assertNotNull(ContentBlock::query()->findOrFail($written->json('blocks.2.id'))->file());

        $blocks[1]['removeImage'] = true;

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/{$partId}", ['name' => 'Zo prik je', 'blocks' => $blocks])
            ->assertOk()
            ->assertJsonPath('blocks.1.fileUrl', null);
    }

    /** A step's title is what it says to do, so one without is refused rather than stored blank. */
    #[Test]
    public function a_step_without_a_title_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();

        $refused = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts", [
                'name' => 'Stap zonder titel',
                'blocks' => [['type' => ContentBlockType::Instruction->value, 'body' => '<p>Alleen tekst.</p>']],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(
            [ModuleMessages::INSTRUCTION_NEEDS_TITLE],
            $this->errorsFor($refused, 'blocks.0.title'),
        );
    }

    /**
     * KOM-100, as reviewed: one carousel block holds its slides, in the order a reader pages through
     * them — not one block per slide.
     */
    #[Test]
    public function a_carousel_holds_its_slides_and_keeps_their_pictures_through_an_edit(): void
    {
        Event::fake([ContentFileDiscarded::class]);
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $picture = static fn (string $name): UploadedFile => UploadedFile::fake()->createWithContent($name, self::PNG);

        $written = $this->withHeaders($this->tokenHeaders($operator))
            ->post($base, [
                'name' => 'Door de slides',
                'blocks' => [[
                    'type' => ContentBlockType::Carousel->value,
                    'slides' => [
                        ['title' => 'Verwijder de beschermhoes', 'body' => '<p>Trek recht.</p>', 'image' => $picture('een.png')],
                        ['title' => 'Pak een nieuwe spuit', 'image' => $picture('twee.png')],
                        ['title' => 'Prik', 'image' => $picture('drie.png')],
                    ],
                ]],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(1, 'blocks')
            ->assertJsonPath('blocks.0.type', ContentBlockType::Carousel->value)
            ->assertJsonCount(3, 'blocks.0.slides')
            ->assertJsonPath('blocks.0.slides.0.title', 'Verwijder de beschermhoes')
            ->assertJsonPath('blocks.0.slides.0.body', '<p>Trek recht.</p>')
            ->assertJsonPath('blocks.0.slides.1.body', null)
            // The block itself holds none of the content columns.
            ->assertJsonPath('blocks.0.fileUrl', null);

        $partId = (string) $written->json('id');
        $blockId = (string) $written->json('blocks.0.id');
        [$first, $second, $third] = (array) $written->json('blocks.0.slides');
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertIsArray($third);

        // Each picture is read back through the course, like a block's file.
        $this->assertSame(
            "/api/e-learnings/{$chapter->e_learning_id}/parts/{$partId}/blocks/{$blockId}/slides/{$first['id']}/image",
            $first['imageUrl'],
        );
        $this->withHeaders($this->tokenHeaders($operator))
            ->get($first['imageUrl'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $removedPicture = (string) CarouselSlide::query()->findOrFail($second['id'])->file_storage_key;

        // Moved, one taken away, one added — and nobody uploads the kept pictures again.
        $edited = $this->withHeaders($this->tokenHeaders($operator))
            ->post("{$base}/{$partId}", [
                '_method' => 'PUT',
                'name' => 'Door de slides',
                'blocks' => [[
                    'id' => $blockId,
                    'type' => ContentBlockType::Carousel->value,
                    'slides' => [
                        ['id' => $third['id'], 'title' => 'Prik'],
                        ['id' => $first['id'], 'title' => 'Verwijder de beschermhoes'],
                        ['title' => 'Druk de zuiger in', 'image' => $picture('vier.png')],
                    ],
                ]],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('blocks.0.id', $blockId)
            ->assertJsonPath('blocks.0.slides.0.id', $third['id'])
            ->assertJsonPath('blocks.0.slides.1.id', $first['id'])
            ->assertJsonPath('blocks.0.slides.2.title', 'Druk de zuiger in');

        $this->assertSame(
            [$third['id'], $first['id']],
            array_slice(array_column((array) $edited->json('blocks.0.slides'), 'id'), 0, 2),
        );
        $this->assertSame(3, CarouselSlide::query()->count());

        // The slide taken away let go of its picture (rule 24).
        Event::assertDispatched(ContentFileDiscarded::class, fn (ContentFileDiscarded $event): bool => $event->storageKey === $removedPicture);
    }

    /** What a carousel cannot be without is refused in Dutch, saying which slide is meant. */
    #[Test]
    public function a_carousel_without_slides_or_with_an_incomplete_slide_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";
        $picture = UploadedFile::fake()->createWithContent('slide.png', self::PNG);

        $refusal = fn (array $slides): TestResponse => $this->withHeaders($this->tokenHeaders($operator))
            ->post($base, [
                'name' => 'Carrousel',
                'blocks' => [['type' => ContentBlockType::Carousel->value, 'slides' => $slides]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(
            [ModuleMessages::CAROUSEL_NEEDS_A_SLIDE],
            $this->errorsFor($refusal([]), 'blocks.0.slides'),
        );
        $this->assertSame(
            [ModuleMessages::slideNeedsImage(2)],
            $this->errorsFor($refusal([['title' => 'Een', 'image' => $picture], ['title' => 'Twee']]), 'blocks.0.slides.1.image'),
        );
        $this->assertSame(
            [ModuleMessages::slideNeedsTitle(1)],
            $this->errorsFor($refusal([['image' => $picture]]), 'blocks.0.slides.0.title'),
        );
        $this->assertSame(
            [ModuleMessages::tooManySlides()],
            $this->errorsFor(
                $refusal(array_fill(0, CarouselSlide::MAXIMUM_PER_CAROUSEL + 1, ['title' => 'Slide'])),
                'blocks.0.slides',
            ),
        );

        // Refused before anything was written: no part, no slide, and no picture left on the disk.
        $this->assertDatabaseCount('steps', 0);
        $this->assertDatabaseCount('carousel_slides', 0);
        $this->assertSame([], Storage::allFiles(CarouselSlide::FILE_PREFIX));
    }

    /** A slide's picture is only found through the carousel and the part it belongs to. */
    #[Test]
    public function a_slide_picture_is_not_found_under_another_block(): void
    {
        $operator = $this->platformAdministrator();
        $slide = CarouselSlide::factory()->create();
        $block = $slide->block;
        $other = ContentBlock::factory()->of($block->step)->create();
        $courseId = $block->step->chapter->e_learning_id;
        $partId = $block->step_id;

        Storage::put($slide->file_storage_key, self::PNG);

        $this->withHeaders($this->tokenHeaders($operator))
            ->get("/api/e-learnings/{$courseId}/parts/{$partId}/blocks/{$block->getKey()}/slides/{$slide->getKey()}/image")
            ->assertOk();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/e-learnings/{$courseId}/parts/{$partId}/blocks/{$other->getKey()}/slides/{$slide->getKey()}/image")
            ->assertNotFound();
    }

    #[Test]
    public function a_part_is_written_with_a_checkbox_block_and_read_back_with_its_options(): void
    {
        // KOM-107, through the API's door: the same rule and the same use case as the panel's.
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $written = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, [
                'name' => 'Voor je begint',
                'blocks' => [
                    ['type' => ContentBlockType::Text->value, 'body' => 'Loop dit na.'],
                    ['type' => ContentBlockType::Checkboxes->value, 'title' => 'Check', 'body' => '<p>Vink af wat klaar is.</p>', 'checkboxes' => [
                        ' Bijsluiter gelezen ', '', 'Naald klaargelegd',
                    ]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('blocks.0.checkboxes', null)
            ->assertJsonPath('blocks.1.type', ContentBlockType::Checkboxes->value)
            ->assertJsonPath('blocks.1.title', 'Check')
            // The body is optional, and kept when there is one (KOM-107).
            ->assertJsonPath('blocks.1.body', '<p>Vink af wat klaar is.</p>')
            // Trimmed, and the blank one left out.
            ->assertJsonPath('blocks.1.checkboxes.0.label', 'Bijsluiter gelezen')
            ->assertJsonPath('blocks.1.checkboxes.1.label', 'Naald klaargelegd');

        // Every option has an identifier of its own.
        $ids = array_column((array) $written->json('blocks.1.checkboxes'), 'id');
        $this->assertCount(2, array_unique(array_filter($ids, 'is_string')));

        $tooMany = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, [
                'name' => 'Te veel opties',
                'blocks' => [[
                    'type' => ContentBlockType::Checkboxes->value,
                    'checkboxes' => array_fill(0, BlockCheckboxes::MAXIMUM_OPTIONS + 1, 'Optie'),
                ]],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::checkboxesTooMany()], $this->errorsFor($tooMany, 'blocks.0.checkboxes'));

        $without = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, ['name' => 'Zonder opties', 'blocks' => [['type' => ContentBlockType::Checkboxes->value]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(
            [ModuleMessages::CHECKBOXES_NEED_AN_OPTION],
            $this->errorsFor($without, 'blocks.0.checkboxes'),
        );
    }

    /**
     * A client keeps a reader's ticks on their device, keyed by each option's identifier, so the
     * identifier has to survive an operator rewording an option or reordering them — and nothing a
     * client sends may take over an option that is not this block's.
     */
    #[Test]
    public function a_checkbox_option_keeps_its_identifier_when_it_is_reworded_or_moved(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $part = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, [
                'name' => 'Voor je begint',
                'blocks' => [['type' => ContentBlockType::Checkboxes->value, 'checkboxes' => ['Bijsluiter gelezen', 'Naald klaargelegd']]],
            ])
            ->assertCreated();

        $partId = (string) $part->json('id');
        $blockId = (string) $part->json('blocks.0.id');
        [$leaflet, $needle] = array_column((array) $part->json('blocks.0.checkboxes'), 'id');

        $edited = $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/{$partId}", [
                'name' => 'Voor je begint',
                'blocks' => [[
                    'id' => $blockId,
                    'type' => ContentBlockType::Checkboxes->value,
                    'checkboxes' => [
                        // Moved to the top and reworded: still the same option.
                        ['id' => $needle, 'label' => 'De naald ligt klaar'],
                        ['id' => $leaflet, 'label' => 'Bijsluiter gelezen'],
                        // New, sent as a plain label.
                        'Handen gewassen',
                    ],
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            [[$needle, 'De naald ligt klaar'], [$leaflet, 'Bijsluiter gelezen']],
            array_map(
                static fn (array $option): array => [$option['id'], $option['label']],
                array_slice((array) $edited->json('blocks.0.checkboxes'), 0, 2),
            ),
        );
        $this->assertNotContains($edited->json('blocks.0.checkboxes.2.id'), [$needle, $leaflet, null]);

        // An identifier this block never had, and one sent twice, both start a new option.
        $other = $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/{$partId}", [
                'name' => 'Voor je begint',
                'blocks' => [[
                    'id' => $blockId,
                    'type' => ContentBlockType::Checkboxes->value,
                    'checkboxes' => [
                        ['id' => $needle, 'label' => 'Eerste'],
                        ['id' => $needle, 'label' => 'Tweede'],
                        ['id' => '01a15e98-109b-732c-a276-e5aedba72351', 'label' => 'Derde'],
                    ],
                ]],
            ])
            ->assertOk()
            ->json('blocks.0.checkboxes');

        $ids = array_column((array) $other, 'id');
        $this->assertSame($needle, $ids[0]);
        $this->assertCount(3, array_unique($ids));
        $this->assertNotContains('01a15e98-109b-732c-a276-e5aedba72351', $ids);
    }

    #[Test]
    public function a_part_is_written_with_a_table_block_and_read_back_with_its_table(): void
    {
        // KOM-101, through the API's door: the same rule and the same use case as the panel's.
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $part = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, [
                'name' => 'Schema',
                'blocks' => [
                    ['type' => ContentBlockType::Text->value, 'body' => 'Zo vaak prik je.'],
                    ['type' => ContentBlockType::Table->value, 'title' => 'Per dag', 'table' => [
                        'columns' => ['Moment', 'Dosis'],
                        'rows' => [['Ochtend', '2 ml'], ['Avond']],
                    ]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('blocks.0.table', null)
            ->assertJsonPath('blocks.1.type', ContentBlockType::Table->value)
            ->assertJsonPath('blocks.1.title', 'Per dag')
            ->assertJsonPath('blocks.1.table.columns', ['Moment', 'Dosis'])
            // A short row is padded: every row has a cell per column.
            ->assertJsonPath('blocks.1.table.rows', [['Ochtend', '2 ml'], ['Avond', '']]);

        $refused = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, [
                'name' => 'Te breed',
                'blocks' => [['type' => ContentBlockType::Table->value, 'table' => ['columns' => array_fill(0, 11, 'Kop')]]],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::tableTooManyColumns()], $this->errorsFor($refused, 'blocks.0.table'));

        $withoutTable = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($base, ['name' => 'Zonder tabel', 'blocks' => [['type' => ContentBlockType::Table->value]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::TABLE_NEEDS_A_COLUMN], $this->errorsFor($withoutTable, 'blocks.0.table'));

        $this->assertIsString($part->json('id'));
    }

    #[Test]
    public function a_member_cannot_write_even_their_own_organizations_copy(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->putJson("/api/modules/{$module->getKey()}/organizations/{$member->organization_id}", ['contacts' => []])
            ->assertForbidden();
    }

    #[Test]
    public function the_platform_sees_who_has_a_module_and_fills_in_any_copy(): void
    {
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Maastricht UMC']);
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/modules/{$module->getKey()}/organizations")
            ->assertOk()
            ->assertJsonPath('items.0.organizationName', 'Maastricht UMC')
            ->assertJsonPath('items.0.hasContactDetails', false);

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/modules/{$module->getKey()}/organizations/{$organization->getKey()}", [
                'contacts' => [$this->contact('Namens het platform')],
            ])
            ->assertOk();

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/modules/{$module->getKey()}/organizations/".Organization::factory()->create()->getKey(), ['contacts' => []])
            ->assertNotFound();
    }

    #[Test]
    public function a_course_is_created_with_its_picture_and_renamed_without_losing_its_modules(): void
    {
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/e-learnings', ['name' => 'Zonder afbeelding'], ['Accept' => 'application/json'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.image.0', 'afbeelding is verplicht.');

        $created = $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/e-learnings', [
                'name' => 'Medicijnen onder de huid prikken',
                'moduleIds' => [(string) $module->getKey()],
                'image' => UploadedFile::fake()->createWithContent('cover.png', self::PNG),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('chapters', []);

        $course = ELearning::query()->findOrFail($created->json('id'));

        $this->assertStringStartsWith('e-learnings/'.$course->getKey().'/', $course->image_storage_key);

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/e-learnings/{$course->getKey()}", ['name' => 'Nieuwe naam'])
            ->assertOk();

        $this->assertSame([(string) $module->getKey()], $course->modules()->pluck('modules.id')->all());
    }

    #[Test]
    public function the_course_listing_shows_a_member_only_the_modules_they_were_given(): void
    {
        $member = User::factory()->create();
        $mine = Module::factory()->create(['name' => 'Onze module']);
        $theirs = Module::factory()->create(['name' => 'Iemand anders zijn module']);
        ModuleActivation::factory()->ofModule($mine)->forOrganization($member->organization)->create();
        $shared = ELearning::factory()->create();
        $shared->modules()->attach([$mine->getKey(), $theirs->getKey()]);
        ELearning::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/e-learnings')
            ->assertOk()
            ->assertJsonPath('totalCount', 1)
            ->assertJsonPath('items.0.modules', [['id' => (string) $mine->getKey(), 'name' => 'Onze module']]);
    }

    #[Test]
    public function chapters_are_added_at_the_end_edited_reordered_and_deleted(): void
    {
        $operator = $this->platformAdministrator();
        $course = ELearning::factory()->create();
        $first = Chapter::factory()->of($course)->create();
        $headers = $this->tokenHeaders($operator);

        $second = $this->withHeaders($headers)
            ->postJson("/api/e-learnings/{$course->getKey()}/chapters", ['name' => 'Samenvatting', 'isSummary' => true])
            ->assertCreated()
            ->assertJsonPath('position', 1)
            ->assertJsonPath('isSummary', true)
            ->json('id');

        $this->withHeaders($headers)
            ->putJson("/api/e-learnings/{$course->getKey()}/chapters/{$second}", ['name' => 'Samenvatting', 'description' => 'Wat je leerde'])
            ->assertOk()
            ->assertJsonPath('description', 'Wat je leerde')
            ->assertJsonPath('isSummary', false);

        $this->withHeaders($headers)
            ->putJson("/api/e-learnings/{$course->getKey()}/chapters/order", ['ids' => [$second, (string) $first->getKey()]])
            ->assertOk()
            ->assertJsonPath('chapters.0.id', $second);

        $this->withHeaders($headers)
            ->deleteJson("/api/e-learnings/{$course->getKey()}/chapters/{$second}")
            ->assertNoContent();

        $this->assertSame([(string) $first->getKey()], $course->chapters()->pluck('id')->all());
    }

    #[Test]
    public function a_chapter_named_under_another_course_is_not_found(): void
    {
        $operator = $this->platformAdministrator();
        $course = ELearning::factory()->create();
        $foreign = Chapter::factory()->create(['name' => 'Van een andere cursus']);

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/e-learnings/{$course->getKey()}/chapters/{$foreign->getKey()}", ['name' => 'Overschreven'])
            ->assertNotFound();

        $this->assertSame('Van een andere cursus', $foreign->fresh()?->name);
    }

    #[Test]
    public function a_step_is_created_with_its_blocks_and_rewritten_keeping_its_picture(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $url = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $created = $this->withHeaders($this->tokenHeaders($operator))
            ->post($url, [
                'name' => 'Stap 1: dit ga je leren',
                'blocks' => [
                    ['type' => ContentBlockType::Text->value, 'title' => 'Welkom', 'body' => 'Hier begint het.'],
                    ['type' => ContentBlockType::Image->value, 'image' => UploadedFile::fake()->createWithContent('spuit.png', self::PNG)],
                    ['type' => ContentBlockType::Video->value, 'videoUrl' => 'https://www.youtube.com/watch?v=abc'],
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('blocks.1.type', ContentBlockType::Image->value);

        $step = Step::query()->findOrFail($created->json('id'));
        [$text, $picture, $video] = $step->blocks->all();

        $this->assertNotNull($picture->file());

        Event::fake([ContentFileDiscarded::class]);

        // The picture moves to the top without being uploaded again, and the video goes.
        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$url}/{$step->getKey()}", [
                'name' => 'Stap 1',
                'blocks' => [
                    ['type' => ContentBlockType::Image->value, 'id' => (string) $picture->getKey(), 'title' => 'Een spuit'],
                    ['type' => ContentBlockType::Text->value, 'id' => (string) $text->getKey(), 'body' => 'Aangepast.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('blocks.0.id', (string) $picture->getKey())
            ->assertJsonPath('blocks.1.body', 'Aangepast.');

        $this->assertSame($picture->file_storage_key, $picture->fresh()?->file_storage_key);
        $this->assertModelMissing($video);
        Event::assertNotDispatched(ContentFileDiscarded::class);
    }

    #[Test]
    public function a_step_refuses_blocks_missing_what_their_kind_needs(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $url = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($url, ['name' => 'Leeg', 'blocks' => []])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.blocks.0', ModuleMessages::STEP_NEEDS_A_BLOCK);

        $withoutText = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($url, ['name' => 'Geen tekst', 'blocks' => [['type' => ContentBlockType::Text->value]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        // The keys carry dots of their own, which a JSON path would read as nesting.
        $this->assertSame([ModuleMessages::BLOCK_NEEDS_BODY], $this->errorsFor($withoutText, 'blocks.0.body'));

        $withoutPicture = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($url, ['name' => 'Geen afbeelding', 'blocks' => [['type' => ContentBlockType::Image->value]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::BLOCK_NEEDS_IMAGE], $this->errorsFor($withoutPicture, 'blocks.0.image'));

        $this->assertDatabaseCount('steps', 0);
        $this->assertDatabaseCount('content_blocks', 0);
    }

    #[Test]
    public function steps_are_reordered_and_one_under_another_chapter_is_not_found(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $first = Step::factory()->of($chapter, 0)->create();
        $second = Step::factory()->of($chapter, 1)->create();
        $foreign = Step::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/order", ['ids' => [(string) $second->getKey(), (string) $first->getKey()]])
            ->assertOk()
            ->assertJsonPath('parts.0.id', (string) $second->getKey());

        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("{$base}/{$foreign->getKey()}")
            ->assertNotFound();

        $this->assertModelExists($foreign);
    }

    #[Test]
    public function the_categories_are_listed_for_the_form(): void
    {
        $member = User::factory()->create();
        ModuleCategory::factory()->named('Medicatie')->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/module-categories')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Medicatie');
    }

    /**
     * The messages under one field of a refusal.
     *
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     * @return list<string>|null
     */
    private function errorsFor(TestResponse $response, string $field): ?array
    {
        $errors = $response->json('errors');

        return is_array($errors) && is_array($errors[$field] ?? null) ? array_values($errors[$field]) : null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function moduleBody(ModuleCategory $category, array $overrides = []): array
    {
        return [
            'name' => 'Subcutaan Injecteren',
            'categoryId' => (string) $category->getKey(),
            'description' => 'Hoe je medicijnen onder de huid prikt.',
            'status' => ModuleStatus::Available->value,
            ...$overrides,
        ];
    }

    /** @return array<string, string> */
    private function contact(string $name): array
    {
        return ['name' => $name, 'jobRole' => 'Verpleegkundige', 'email' => 'contact@example.nl'];
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
