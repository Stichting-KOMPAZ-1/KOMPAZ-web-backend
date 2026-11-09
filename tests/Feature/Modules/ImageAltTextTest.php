<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Enums\ModuleStatus;
use App\Enums\UserRole;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Support\Modules\ContentRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** A description of every picture a reader sees, written and read back through the API. */
final class ImageAltTextTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function a_course_keeps_its_alt_text_until_it_is_restated_or_its_picture_is_replaced(): void
    {
        $operator = $this->platformAdministrator();

        $created = $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/e-learnings', [
                'name' => 'Ogen druppelen',
                'image' => $this->picture(),
                'imageAlt' => ' Iemand die rustig oogdruppels toedient ',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imageAlt', 'Iemand die rustig oogdruppels toedient');

        $id = (string) $created->json('id');

        // Left out of a rename, it stays.
        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/e-learnings/{$id}", ['name' => 'Ogen druppelen, nieuw'])
            ->assertOk()
            ->assertJsonPath('imageAlt', 'Iemand die rustig oogdruppels toedient');

        // Stated as nothing, it goes.
        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("/api/e-learnings/{$id}", ['name' => 'Ogen druppelen, nieuw', 'imageAlt' => null])
            ->assertOk()
            ->assertJsonPath('imageAlt', null);

        // A new picture comes with its own description.
        $this->withHeaders($this->tokenHeaders($operator))
            ->post("/api/e-learnings/{$id}/image", [
                '_method' => 'PUT',
                'image' => $this->picture(),
                'imageAlt' => 'Een druppelflesje boven een oog',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imageAlt', 'Een druppelflesje boven een oog');

        $this->assertSame('Een druppelflesje boven een oog', ELearning::query()->findOrFail($id)->image_alt);
    }

    #[Test]
    public function a_modules_alt_text_is_written_with_it_and_replaced_with_its_picture(): void
    {
        $operator = $this->platformAdministrator();
        $category = ModuleCategory::factory()->create();

        $created = $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/modules', [
                'name' => 'Steunkousen',
                'categoryId' => (string) $category->getKey(),
                'description' => 'Aan- en uittrekken.',
                'status' => ModuleStatus::Available->value,
                'image' => $this->picture(),
                'imageAlt' => 'Een been met een steunkous',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imageAlt', 'Een been met een steunkous');

        $id = (string) $created->json('id');

        // Replaced without one, the new picture has none: the old text described the old picture.
        $this->withHeaders($this->tokenHeaders($operator))
            ->post("/api/modules/{$id}/image", ['_method' => 'PUT', 'image' => $this->picture()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imageAlt', null);
    }

    #[Test]
    public function a_parts_pictures_carry_their_alt_text_and_lose_it_with_the_picture(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();
        $base = "/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts";

        $written = $this->withHeaders($this->tokenHeaders($operator))
            ->post($base, [
                'name' => 'Materialen',
                'blocks' => [
                    ['type' => ContentBlockType::Image->value, 'image' => $this->picture(), 'imageAlt' => 'Tissues en een druppelflesje'],
                    ['type' => ContentBlockType::Instruction->value, 'title' => 'Pak het flesje', 'image' => $this->picture(), 'imageAlt' => 'Een hand met een flesje'],
                    // Nothing for it to describe, so it is not kept.
                    ['type' => ContentBlockType::Text->value, 'body' => '<p>Tekst.</p>', 'imageAlt' => 'Niets'],
                    [
                        'type' => ContentBlockType::Carousel->value,
                        'slides' => [['title' => 'Tissues', 'image' => $this->picture(), 'imageAlt' => 'Een doos tissues']],
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('blocks.0.imageAlt', 'Tissues en een druppelflesje')
            ->assertJsonPath('blocks.1.imageAlt', 'Een hand met een flesje')
            ->assertJsonPath('blocks.2.imageAlt', null)
            ->assertJsonPath('blocks.3.slides.0.imageAlt', 'Een doos tissues');

        // A "Stap" whose picture is taken away loses the description of it.
        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson("{$base}/{$written->json('id')}", [
                'name' => 'Materialen',
                'blocks' => [[
                    'id' => $written->json('blocks.1.id'),
                    'type' => ContentBlockType::Instruction->value,
                    'title' => 'Pak het flesje',
                    'imageAlt' => 'Een hand met een flesje',
                    'removeImage' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('blocks.0.fileUrl', null)
            ->assertJsonPath('blocks.0.imageAlt', null);
    }

    #[Test]
    public function alt_text_longer_than_a_column_holds_is_refused(): void
    {
        $chapter = Chapter::factory()->create();

        $this->withHeaders($this->tokenHeaders($this->platformAdministrator()))
            ->post("/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts", [
                'name' => 'Te lang',
                'blocks' => [[
                    'type' => ContentBlockType::Image->value,
                    'image' => $this->picture(),
                    'imageAlt' => str_repeat('a', ContentRules::MAXIMUM_IMAGE_ALT_LENGTH + 1),
                ]],
            ], ['Accept' => 'application/json'])
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('errors', fn (array $errors): bool => isset($errors['blocks.0.imageAlt']));
    }

    #[Test]
    public function the_table_refuses_alt_text_with_no_picture_to_describe(): void
    {
        $module = Module::factory()->create(['image_storage_key' => null, 'image_content_type' => null, 'image_byte_count' => null]);

        $this->expectException(QueryException::class);

        $module->forceFill(['image_alt' => 'Niets'])->save();
    }

    #[Test]
    public function the_table_refuses_alt_text_on_a_video(): void
    {
        $block = ContentBlock::query()->create([
            'step_id' => Step::factory()->create()->getKey(),
            'type' => ContentBlockType::Video,
            'video_url' => 'https://vimeo.com/1',
            'position' => 0,
        ]);

        $this->expectException(QueryException::class);

        $block->forceFill(['file_storage_key' => 'videos/x.mp4', 'file_content_type' => 'video/mp4', 'file_byte_count' => 1, 'video_url' => null, 'file_alt' => 'Niets'])->save();
    }

    private function picture(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('picture.jpg', self::PNG);
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
