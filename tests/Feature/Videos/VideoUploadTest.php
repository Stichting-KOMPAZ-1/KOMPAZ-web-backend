<?php

declare(strict_types=1);

namespace Tests\Feature\Videos;

use App\Enums\ContentBlockType;
use App\Enums\UserRole;
use App\Events\ContentFileDiscarded;
use App\Listeners\DeleteDiscardedContentFile;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Models\VideoUpload;
use App\Support\Videos\VideoFormat;
use App\Support\Videos\VideoMessages;
use App\Support\Videos\VideoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Uploading a video: a link to write it to, a look at what was written, and spending it on a video.
 *
 * The bytes go straight to the video container and never through here, so what these tests guard
 * is everything around them — who may upload, that the format and size are read off the blob
 * rather than believed, and above all that an upload can only ever end up on a video by the
 * person it was issued to, once. An upload anybody could name would let one organization show
 * another's video under its own module.
 */
final class VideoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const string MP4 = "\x00\x00\x00\x18".'ftyp'.'mp42'.'the rest of a video';

    #[Test]
    public function an_administrator_is_issued_a_link_to_write_one_video_to(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson('/api/video-uploads', ['byteCount' => 5_000_000])
            ->assertCreated()
            ->assertJsonPath('isVerified', false)
            ->assertJsonPath('blockSizeBytes', (int) config('kompaz.videos.block_size_bytes'))
            ->assertJsonPath('uploadHeaders.x-ms-blob-type', 'BlockBlob');

        $upload = VideoUpload::query()->findOrFail($response->json('id'));

        // The key is minted here, under the video prefix, and the link is for that key alone.
        $this->assertSame(VideoStorage::keyFor((string) $upload->getKey()), $upload->storage_key);
        $this->assertSame("https://videos.test/{$upload->storage_key}?sig=write", $response->json('uploadUrl'));
        $this->assertSame((string) $administrator->getKey(), $upload->issued_to);
    }

    #[Test]
    public function a_member_may_not_upload_a_video(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->withHeaders($this->tokenHeaders($member))
            ->postJson('/api/video-uploads', ['byteCount' => 5_000_000])
            ->assertForbidden();

        $this->assertDatabaseCount('video_uploads', 0);
    }

    #[Test]
    public function a_video_said_to_be_too_large_is_refused_before_a_byte_is_sent(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson('/api/video-uploads', ['byteCount' => VideoMessages::maximumBytes() + 1])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.byteCount.0', VideoMessages::tooLarge());

        $this->assertDatabaseCount('video_uploads', 0);
    }

    #[Test]
    public function completing_reads_the_format_and_size_off_the_blob(): void
    {
        $administrator = User::factory()->administrator()->create();
        $upload = VideoUpload::factory()->issuedTo($administrator)->create(['declared_byte_count' => 1]);
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, self::MP4);

        $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson("/api/video-uploads/{$upload->getKey()}/complete")
            ->assertOk()
            ->assertJsonPath('isVerified', true)
            ->assertJsonPath('contentType', VideoFormat::MP4)
            ->assertJsonPath('byteCount', strlen(self::MP4))
            // The link is a credential and is handed out once, when it is issued.
            ->assertJsonMissingPath('uploadUrl');
    }

    #[Test]
    public function a_file_that_is_not_a_video_is_refused_and_removed(): void
    {
        $administrator = User::factory()->administrator()->create();
        $upload = VideoUpload::factory()->issuedTo($administrator)->create();
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, '<html>not a video</html>');

        $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson("/api/video-uploads/{$upload->getKey()}/complete")
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.upload.0', VideoMessages::NOT_A_VIDEO);

        $this->assertModelMissing($upload);
        Storage::disk(VideoStorage::DISK)->assertMissing($upload->storage_key);
    }

    #[Test]
    public function a_video_larger_than_allowed_is_refused_and_removed_whatever_was_declared(): void
    {
        config(['kompaz.videos.maximum_size_bytes' => 10]);

        $administrator = User::factory()->administrator()->create();
        $upload = VideoUpload::factory()->issuedTo($administrator)->create(['declared_byte_count' => 5]);
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, self::MP4);

        $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson("/api/video-uploads/{$upload->getKey()}/complete")
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.upload.0', VideoMessages::tooLarge());

        $this->assertModelMissing($upload);
        Storage::disk(VideoStorage::DISK)->assertMissing($upload->storage_key);
    }

    #[Test]
    public function an_upload_with_nothing_written_yet_is_not_complete(): void
    {
        $administrator = User::factory()->administrator()->create();
        $upload = VideoUpload::factory()->issuedTo($administrator)->create();

        $this->withHeaders($this->tokenHeaders($administrator))
            ->postJson("/api/video-uploads/{$upload->getKey()}/complete")
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors.upload.0', VideoMessages::UPLOAD_INCOMPLETE);

        $this->assertModelExists($upload);
    }

    #[Test]
    public function somebody_elses_upload_cannot_be_completed(): void
    {
        $upload = VideoUpload::factory()->create();
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, self::MP4);

        $this->withHeaders($this->tokenHeaders(User::factory()->administrator()->create()))
            ->postJson("/api/video-uploads/{$upload->getKey()}/complete")
            ->assertNotFound();

        $this->assertFalse((bool) $upload->fresh()?->isVerified());
    }

    #[Test]
    public function issuing_an_upload_clears_away_ones_nobody_finished(): void
    {
        // There is no scheduler, so the next upload somebody starts is what tidies up.
        $abandoned = VideoUpload::factory()->expired()->create();
        $used = VideoUpload::factory()->verified()->claimed()->create(['expires_at' => Carbon::now()->subDay()]);
        $inProgress = VideoUpload::factory()->create();

        $videos = Storage::disk(VideoStorage::DISK);

        foreach ([$abandoned, $used, $inProgress] as $upload) {
            $videos->put($upload->storage_key, self::MP4);
        }

        $this->withHeaders($this->tokenHeaders(User::factory()->administrator()->create()))
            ->postJson('/api/video-uploads', ['byteCount' => 100])
            ->assertCreated();

        $this->assertModelMissing($abandoned);
        $videos->assertMissing($abandoned->storage_key);

        // A claimed upload's blob is a video's now, and one still being written is somebody's.
        $videos->assertExists($used->storage_key);
        $videos->assertExists($inProgress->storage_key);
    }

    #[Test]
    public function an_organization_administrator_puts_their_own_upload_on_their_copy_of_a_module(): void
    {
        [$administrator, $module, $url] = $this->organizationsCopy();
        $upload = $this->finishedUploadFor($administrator);

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [['title' => 'Onze instructie', 'uploadId' => (string) $upload->getKey()]]])
            ->assertOk()
            ->assertJsonPath('videos.0.url', null);

        $video = ModuleVideo::query()->sole();

        $this->assertSame($upload->storage_key, $video->file_storage_key);
        $this->assertSame(VideoFormat::MP4, $video->file_content_type);
        $this->assertNotNull($upload->fresh()?->claimed_at);

        // And a reader of the module is sent on to it.
        $this->withHeaders($this->tokenHeaders($administrator))
            ->get("/api/modules/{$module->getKey()}/videos/{$video->getKey()}/file")
            ->assertRedirect("https://videos.test/{$upload->storage_key}?sig=read");
    }

    #[Test]
    public function an_upload_issued_to_somebody_else_cannot_be_named(): void
    {
        // The whole reason an upload is a row: its key alone must not be enough.
        [$administrator, , $url] = $this->organizationsCopy();
        $theirs = $this->finishedUploadFor(User::factory()->administrator()->create());

        $response = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [['title' => 'Niet van ons', 'uploadId' => (string) $theirs->getKey()]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([VideoMessages::UPLOAD_UNAVAILABLE], $this->errorsFor($response, 'videos.0.uploadId'));
        $this->assertDatabaseCount('module_videos', 0);
        $this->assertNull($theirs->fresh()?->claimed_at);
    }

    #[Test]
    public function an_upload_is_spent_once(): void
    {
        [$administrator, , $url] = $this->organizationsCopy();
        $upload = $this->finishedUploadFor($administrator);
        $uploadId = (string) $upload->getKey();

        $response = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [
                ['title' => 'Eerste', 'uploadId' => $uploadId],
                ['title' => 'Tweede', 'uploadId' => $uploadId],
            ]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        // Two videos on one blob would each take the other's bytes with them when deleted.
        $this->assertSame([VideoMessages::UPLOAD_UNAVAILABLE], $this->errorsFor($response, 'videos.1.uploadId'));
        $this->assertDatabaseCount('module_videos', 0);

        // The refused save rolled its claim back, so the upload can still be used.
        $this->assertNull($upload->fresh()?->claimed_at);
    }

    #[Test]
    public function an_upload_not_yet_looked_at_cannot_be_used(): void
    {
        [$administrator, , $url] = $this->organizationsCopy();
        $upload = VideoUpload::factory()->issuedTo($administrator)->create();

        $response = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [['title' => 'Te vroeg', 'uploadId' => (string) $upload->getKey()]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([VideoMessages::UPLOAD_INCOMPLETE], $this->errorsFor($response, 'videos.0.uploadId'));
    }

    #[Test]
    public function a_video_is_a_link_or_an_upload_and_never_both_or_neither(): void
    {
        [$administrator, , $url] = $this->organizationsCopy();
        $upload = $this->finishedUploadFor($administrator);

        $both = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [[
                'title' => 'Allebei',
                'url' => 'https://www.youtube.com/watch?v=abc',
                'uploadId' => (string) $upload->getKey(),
            ]]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([VideoMessages::LINK_OR_UPLOAD], $this->errorsFor($both, 'videos.0.uploadId'));

        $neither = $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [['title' => 'Geen van beide']]])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([VideoMessages::NEEDS_SOURCE], $this->errorsFor($neither, 'videos.0.url'));
        $this->assertDatabaseCount('module_videos', 0);
    }

    #[Test]
    public function an_edit_keeps_an_upload_it_does_not_send_again_and_a_link_replaces_it(): void
    {
        [$administrator, , $url, $activation] = $this->organizationsCopy();
        $video = ModuleVideo::factory()->ofActivation($activation)->uploaded()->create(['title' => 'Oud']);
        $key = (string) $video->file_storage_key;

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [['id' => (string) $video->getKey(), 'title' => 'Nieuw']]])
            ->assertOk();

        $kept = $video->fresh();

        $this->assertNotNull($kept);
        $this->assertSame($key, $kept->file_storage_key);
        $this->assertSame('Nieuw', $kept->title);

        Event::fake([ContentFileDiscarded::class]);

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson($url, ['videos' => [[
                'id' => (string) $video->getKey(),
                'title' => 'Nieuw',
                'url' => 'https://www.youtube.com/watch?v=abc',
            ]]])
            ->assertOk();

        $linked = $video->fresh();

        $this->assertNotNull($linked);
        $this->assertNull($linked->file_storage_key);
        Event::assertDispatched(ContentFileDiscarded::class, fn (ContentFileDiscarded $event): bool => $event->storageKey === $key);
    }

    #[Test]
    public function the_platform_puts_an_upload_on_a_step_and_a_reader_is_sent_on_to_it(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $upload = $this->finishedUploadFor($operator);
        $url = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $created = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson($url, [
                'name' => 'Stap 1',
                'blocks' => [['type' => ContentBlockType::Video->value, 'videoUploadId' => (string) $upload->getKey()]],
            ])
            ->assertCreated()
            ->assertJsonPath('blocks.0.videoUrl', null);

        $block = Step::query()->findOrFail($created->json('id'))->blocks()->sole();
        $this->assertSame($upload->storage_key, $block->file_storage_key);

        $this->withHeaders($this->tokenHeaders($operator))
            ->get("/api/e-learnings/{$chapter->e_learning_id}/parts/{$block->step_id}/blocks/{$block->getKey()}/file")
            ->assertRedirect("https://videos.test/{$upload->storage_key}?sig=read");
    }

    #[Test]
    public function a_video_block_with_neither_a_link_nor_an_upload_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();

        $response = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts", [
                'name' => 'Stap 1',
                'blocks' => [['type' => ContentBlockType::Video->value]],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([VideoMessages::NEEDS_SOURCE], $this->errorsFor($response, 'blocks.0.videoUrl'));
        $this->assertDatabaseCount('content_blocks', 0);
    }

    #[Test]
    public function a_discarded_video_is_removed_from_the_video_disk_and_nothing_else_is(): void
    {
        // The key says which disk a file is on: a video's and a picture's are let go of by the
        // same event, and each must reach its own disk.
        $video = VideoStorage::keyFor('a-video');
        $picture = ContentBlock::FILE_PREFIX.'/a-picture.png';

        Storage::disk(VideoStorage::DISK)->put($video, self::MP4);
        Storage::put($picture, 'picture');

        $listener = new DeleteDiscardedContentFile;
        $listener->handle(new ContentFileDiscarded($video));

        Storage::disk(VideoStorage::DISK)->assertMissing($video);
        Storage::assertExists($picture);

        $listener->handle(new ContentFileDiscarded($picture));

        Storage::assertMissing($picture);
    }

    /**
     * An organization administrator, the module they were given, and their copy's address.
     *
     * @return array{0: User, 1: Module, 2: string, 3: ModuleActivation}
     */
    private function organizationsCopy(): array
    {
        $administrator = User::factory()->administrator()->create();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->forOrganization($administrator->organization)->create();

        return [
            $administrator,
            $module,
            "/api/modules/{$module->getKey()}/organizations/{$administrator->organization_id}",
            $activation,
        ];
    }

    private function finishedUploadFor(User $user): VideoUpload
    {
        $upload = VideoUpload::factory()->issuedTo($user)->verified()->create();
        Storage::disk(VideoStorage::DISK)->put($upload->storage_key, self::MP4);

        return $upload;
    }

    /**
     * The messages under one field of a refusal.
     *
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     * @return list<mixed>|null
     */
    private function errorsFor(TestResponse $response, string $field): ?array
    {
        $errors = $response->json('errors');

        return is_array($errors) && is_array($errors[$field] ?? null) ? array_values($errors[$field]) : null;
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
