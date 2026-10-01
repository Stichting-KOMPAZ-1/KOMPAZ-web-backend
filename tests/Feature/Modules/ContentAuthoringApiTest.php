<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Enums\ModuleStatus;
use App\Enums\UserRole;
use App\Events\ContentFileDiscarded;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\ModuleContact;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
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
