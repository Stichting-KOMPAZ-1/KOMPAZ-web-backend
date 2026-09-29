<?php

declare(strict_types=1);

namespace Tests\Feature\Videos;

use App\Enums\LoginTokenPurpose;
use App\Events\ContentFileDiscarded;
use App\Models\ContentBlock;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Models\VideoUpload;
use App\Nova\Fields\VideoUpload as VideoUploadField;
use App\Services\SecretTokenFactory;
use App\Support\Videos\VideoMessages;
use App\Support\Videos\VideoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Uploading a video from the panel's forms, driven through Nova's own HTTP API.
 *
 * The field sends the form nothing but an upload's key; the bytes went to Azure beforehand. What
 * matters here is what the panel does with that key, and what it does on every save that does
 * *not* send one — Nova's own preset used to delete and re-insert every video row on each save,
 * which for an upload would have been a lost file every time somebody fixed a typo.
 */
final class VideoUploadPanelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_panel_is_issued_an_upload_link_through_its_own_path(): void
    {
        // The frontend's nginx forwards `/nova-vendor` to this application and not `/api`, and the
        // panel has a session rather than a token.
        $operator = $this->signedInOperator();

        $response = $this->postJson(VideoUploadField::UPLOADS_PATH, ['byteCount' => 1_000])
            ->assertCreated();

        $this->assertSame(
            (string) $operator->getKey(),
            VideoUpload::query()->findOrFail($response->json('id'))->issued_to,
        );
    }

    #[Test]
    public function the_platform_puts_an_upload_on_a_modules_videos(): void
    {
        $operator = $this->signedInOperator();
        $module = Module::factory()->create();
        $upload = $this->finishedUploadFor($operator);

        $this->putJson("/nova-api/modules/{$module->getKey()}", [
            ...$this->moduleForm($module),
            'videos' => [$this->videoRow('Zo prik je', uploadId: (string) $upload->getKey())],
        ])->assertOk();

        $video = $module->videos()->sole();

        $this->assertSame($upload->storage_key, $video->file_storage_key);
        $this->assertNull($video->url);
    }

    #[Test]
    public function saving_the_form_again_keeps_the_upload_and_removing_the_row_lets_go_of_it(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();
        $video = ModuleVideo::factory()->ofModule($module)->uploaded()->create(['title' => 'Oud']);
        $key = (string) $video->file_storage_key;

        Event::fake([ContentFileDiscarded::class]);

        // What an edit sends for a row it did not touch: its key and its title, and no video.
        $this->putJson("/nova-api/modules/{$module->getKey()}", [
            ...$this->moduleForm($module),
            'videos' => [$this->videoRow('Nieuw', id: (string) $video->getKey())],
        ])->assertOk();

        $kept = $video->fresh();

        $this->assertNotNull($kept);
        $this->assertSame($key, $kept->file_storage_key);
        $this->assertSame('Nieuw', $kept->title);
        Event::assertNotDispatched(ContentFileDiscarded::class);

        $this->putJson("/nova-api/modules/{$module->getKey()}", [
            ...$this->moduleForm($module),
            'videos' => [],
        ])->assertOk();

        $this->assertModelMissing($video);
        Event::assertDispatched(ContentFileDiscarded::class, fn (ContentFileDiscarded $event): bool => $event->storageKey === $key);
    }

    #[Test]
    public function a_row_with_neither_a_link_nor_an_upload_keeps_the_dialog_open_under_its_field(): void
    {
        // A 422 with the sentence under the field, which is what keeps Nova's form open.
        $this->signedInOperator();
        $module = Module::factory()->create();

        $this->putJson("/nova-api/modules/{$module->getKey()}", [
            ...$this->moduleForm($module),
            'videos' => [$this->videoRow('Niets')],
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['videos.0.fields.url' => VideoMessages::NEEDS_SOURCE]);

        $this->assertDatabaseCount('module_videos', 0);
    }

    #[Test]
    public function an_organization_administrator_puts_their_own_upload_on_their_copy(): void
    {
        $administrator = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()->forOrganization($administrator->organization)->create();
        $upload = $this->finishedUploadFor($administrator);

        $this->putJson('/nova-api/module-activations/'.$activation->getKey(), [
            'videos' => [$this->videoRow('Onze instructie', uploadId: (string) $upload->getKey())],
        ])->assertOk();

        $this->assertSame($upload->storage_key, $activation->videos()->sole()->file_storage_key);
    }

    #[Test]
    public function a_step_gets_an_uploaded_video_block(): void
    {
        $operator = $this->signedInOperator();
        $step = Step::factory()->create();
        $upload = $this->finishedUploadFor($operator);

        $this->put("/nova-api/steps/{$step->getKey()}", [
            'chapter' => (string) $step->chapter_id,
            'name' => $step->name,
            'blocks' => [[
                'type' => 'video-block-repeatable',
                'fields' => ['title' => 'Zo prik je', VideoUploadField::ATTRIBUTE => (string) $upload->getKey()],
            ]],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame($upload->storage_key, $step->blocks()->sole()->file_storage_key);
    }

    #[Test]
    public function the_forms_open_and_show_the_upload_a_row_already_has(): void
    {
        // A field's callbacks run against the row, and against no row at all on a create form —
        // the create form answering 500 is the lesson a picture's preview taught.
        $this->signedInOperator();
        $module = Module::factory()->create();
        ModuleVideo::factory()->ofModule($module)->uploaded()->create();
        $step = Step::factory()->create();
        ContentBlock::factory()->of($step)->uploadedVideo()->create();

        $this->getJson('/nova-api/modules/creation-fields')->assertOk();
        $this->getJson('/nova-api/steps/creation-fields')->assertOk();

        foreach (["/nova-api/modules/{$module->getKey()}/update-fields", "/nova-api/steps/{$step->getKey()}/update-fields"] as $url) {
            $upload = $this->uploadFieldIn($this->getJson($url)->assertOk()->json('fields'));

            $this->assertSame('video-upload', $upload['component']);
            $this->assertNull($upload['value']);
            $this->assertSame(2048, $upload['current']['byteCount']);
            $this->assertStringStartsWith('/nova-vendor/kompaz/video-previews/', $upload['current']['previewUrl']);
        }
    }

    #[Test]
    public function the_platform_plays_back_the_uploads_it_is_editing(): void
    {
        $this->signedInOperator();
        $video = ModuleVideo::factory()->ofModule(Module::factory()->create())->uploaded()->create();
        $block = ContentBlock::factory()->of(Step::factory()->create())->uploadedVideo()->create();

        $this->get(route('nova.video-preview.module-video', ['moduleVideo' => $video->getKey()]))
            ->assertRedirect("https://videos.test/{$video->file_storage_key}?sig=read");

        $this->get(route('nova.video-preview.content-block', ['contentBlock' => $block->getKey()]))
            ->assertRedirect("https://videos.test/{$block->file_storage_key}?sig=read");
    }

    #[Test]
    public function an_organization_administrator_plays_back_their_own_videos_and_nobody_elses(): void
    {
        // Asked the way saving is asked: their own copy's videos are theirs, and the platform's
        // videos, a step's blocks and another organization's copy are not.
        $administrator = $this->signedInAdministrator();
        $mine = ModuleVideo::factory()
            ->ofActivation(ModuleActivation::factory()->forOrganization($administrator->organization)->create())
            ->uploaded()->create();
        $theirs = ModuleVideo::factory()->ofActivation(ModuleActivation::factory()->create())->uploaded()->create();
        $platforms = ModuleVideo::factory()->ofModule(Module::factory()->create())->uploaded()->create();
        $block = ContentBlock::factory()->of(Step::factory()->create())->uploadedVideo()->create();

        $this->get(route('nova.video-preview.module-video', ['moduleVideo' => $mine->getKey()]))
            ->assertRedirect("https://videos.test/{$mine->file_storage_key}?sig=read");

        $this->getJson(route('nova.video-preview.module-video', ['moduleVideo' => $theirs->getKey()]))->assertForbidden();
        $this->getJson(route('nova.video-preview.module-video', ['moduleVideo' => $platforms->getKey()]))->assertForbidden();
        $this->getJson(route('nova.video-preview.content-block', ['contentBlock' => $block->getKey()]))->assertForbidden();
    }

    /**
     * The upload field of the first row of whichever repeater on the form has one.
     *
     * @return array<string, mixed>
     */
    private function uploadFieldIn(mixed $fields): array
    {
        $this->assertIsArray($fields);

        foreach ($fields as $field) {
            foreach (is_array($field['value'] ?? null) ? $field['value'] : [] as $row) {
                foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $rowField) {
                    if (($rowField['attribute'] ?? null) === VideoUploadField::ATTRIBUTE) {
                        return $rowField;
                    }
                }
            }
        }

        $this->fail('The form has no video upload field in any row.');
    }

    /** @return array<string, mixed> */
    private function moduleForm(Module $module): array
    {
        return [
            'name' => $module->name,
            'category_id' => (string) $module->category_id,
            'description' => $module->description,
            'status' => $module->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function videoRow(string $title, ?string $url = null, ?string $uploadId = null, ?string $id = null): array
    {
        return ['type' => 'module-video-repeatable', 'fields' => array_filter([
            'id' => $id,
            'title' => $title,
            'url' => $url,
            VideoUploadField::ATTRIBUTE => $uploadId,
        ], static fn (?string $value): bool => $value !== null)];
    }

    private function finishedUploadFor(User $user): VideoUpload
    {
        $upload = VideoUpload::factory()->issuedTo($user)->verified()->create();
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, 'bytes');

        return $upload;
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

    /** Claims a fresh link for the given user, which is the only way into the panel. */
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
