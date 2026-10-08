<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Enums\LoginTokenPurpose;
use App\Enums\ModuleStatus;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Nova\Fields\RichText;
use App\Services\SecretTokenFactory;
use App\Support\Html\SanitizedHtml;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The prose an operator writes in the panel's editor, and what a row is allowed to keep of it.
 *
 * Content has two doors and one set of rules (rule 25), and markup is the case where that is least
 * obvious: the panel's editor cleans nothing, the API is handed whatever a client sends, and a
 * block's body reaches its row without the field that drew it ever filling anything. So the
 * cleaning is a cast on the column and these tests come at it from both sides — what survives is
 * the same either way, or the two doors store different things under one name.
 */
final class RichTextContentTest extends TestCase
{
    use RefreshDatabase;

    /** What an editor produces, with the two things an allowlist is for mixed in. */
    private const string DIRTY = '<p>Prik <strong>langzaam</strong>.</p>'
        .'<script>alert(1)</script>'
        .'<p onclick="steal()">En wacht.</p>';

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    private const string CLEAN = '<p>Prik <strong>langzaam</strong>.</p><p>En wacht.</p>';

    /** Markup that is nothing but the parts an allowlist removes. */
    private const string ENTIRELY_UNSAFE = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function a_chapters_description_is_plain_text_kept_as_typed(): void
    {
        // Plain text again, as a module's description is: nothing is stripped, so a `<` in the
        // text is part of the sentence.
        $operator = $this->platformAdministrator();
        $course = ELearning::factory()->create();
        $description = "Prik <langzaam> & wacht.\n\nDaarna pas loslaten.";

        $response = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/e-learnings/{$course->getKey()}/chapters", [
                'name' => 'Hoofdstuk 1',
                'description' => $description,
            ])
            ->assertCreated()
            ->assertJsonPath('description', $description);

        $this->assertSame($description, Chapter::query()->findOrFail($response->json('id'))->description);
    }

    #[Test]
    public function a_modules_description_and_source_are_plain_text_kept_as_typed(): void
    {
        // Not markup: the product never asked for it there, and a client shows them as written.
        // So nothing is stripped either — a `<` in the text is part of the sentence.
        $operator = $this->platformAdministrator();
        $category = ModuleCategory::factory()->create();
        $description = "Prik <langzaam> & wacht.\n\nDaarna pas loslaten.";

        $response = $this->withHeaders($this->tokenHeaders($operator))
            ->post('/api/modules', [
                'name' => 'Subcutaan Injecteren',
                'categoryId' => (string) $category->getKey(),
                'description' => $description,
                'sourceAttribution' => 'Richtlijn 2026',
                'status' => ModuleStatus::Available->value,
                'image' => UploadedFile::fake()->createWithContent('cover.png', self::PNG),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('description', $description)
            ->assertJsonPath('sourceAttribution', 'Richtlijn 2026');

        $this->assertSame($description, Module::query()->findOrFail($response->json('id'))->description);
    }

    #[Test]
    public function a_step_block_written_through_the_api_keeps_its_markup(): void
    {
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts", [
                'name' => 'Stap 1: dit ga je leren',
                'blocks' => [['type' => ContentBlockType::Text->value, 'body' => self::DIRTY]],
            ])
            ->assertCreated()
            ->assertJsonPath('blocks.0.body', self::CLEAN);
    }

    #[Test]
    public function a_step_block_written_in_the_panel_keeps_the_same_markup(): void
    {
        // The other door. A block's body never passes through the field that drew it — the preset
        // reads it off the request — so this is the path a field-level sanitizer would have missed.
        $this->signedInOperator();
        $chapter = Chapter::factory()->create();

        $this->post('/nova-api/steps', [
            'chapter' => (string) $chapter->getKey(),
            'name' => 'Stap 1: dit ga je leren',
            'blocks' => [
                ['type' => 'text-block-repeatable', 'fields' => ['title' => 'Welkom', 'body' => self::DIRTY]],
            ],
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $step = Step::query()->where('name', 'Stap 1: dit ga je leren')->sole();

        $this->assertSame(self::CLEAN, $step->blocks->first()?->body);
    }

    #[Test]
    public function an_organizations_own_contact_details_are_cleaned_the_same_way(): void
    {
        // Written by an organization administrator rather than the platform, which is the whole
        // reason this one matters: it is the only prose here somebody outside the platform writes.
        $organization = Organization::factory()->create();
        $administrator = User::factory()->administrator()->for($organization)->create();
        $activation = ModuleActivation::factory()->forOrganization($organization)->create();

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson("/api/modules/{$activation->module_id}/organizations/{$organization->getKey()}", [
                'contacts' => [[
                    'name' => 'Team Zorg',
                    'jobRole' => 'Verpleegkundigen',
                    'email' => 'zorg@example.nl',
                    'reason' => self::DIRTY,
                    'availability' => '<p>Ma t/m vr</p>',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('contacts.0.reason', self::CLEAN)
            ->assertJsonPath('contacts.0.availability', '<p>Ma t/m vr</p>');
    }

    #[Test]
    public function prose_that_may_be_left_out_reads_back_as_absent_when_nothing_survives(): void
    {
        $organization = Organization::factory()->create();
        $administrator = User::factory()->administrator()->for($organization)->create();
        $activation = ModuleActivation::factory()->forOrganization($organization)->create();

        $this->withHeaders($this->tokenHeaders($administrator))
            ->putJson("/api/modules/{$activation->module_id}/organizations/{$organization->getKey()}", [
                'contacts' => [[
                    'name' => 'Team Zorg',
                    'jobRole' => 'Verpleegkundigen',
                    'email' => 'zorg@example.nl',
                    'availability' => self::ENTIRELY_UNSAFE,
                ]],
            ])
            ->assertOk()
            // Null rather than an empty string: a column that may be left out reads as left out,
            // so a client is not handed blank prose to render.
            ->assertJsonPath('contacts.0.availability', null);
    }

    #[Test]
    public function prose_that_may_not_be_blank_is_refused_before_the_column_can_be(): void
    {
        // `required` is satisfied — something was sent — and nothing survives cleaning it. Without
        // the rule the row would be refused by the column instead, which is a 500 and not a
        // sentence an operator can read.
        $operator = $this->platformAdministrator();
        $chapter = Chapter::factory()->create();

        $step = $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/e-learnings/{$chapter->e_learning_id}/chapters/{$chapter->getKey()}/parts", [
                'name' => 'Leeg blok',
                'blocks' => [['type' => ContentBlockType::Text->value, 'body' => self::ENTIRELY_UNSAFE]],
            ])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame([ModuleMessages::BLOCK_NEEDS_BODY], $this->errorsFor($step, 'blocks.0.body'));

        $this->assertDatabaseCount('content_blocks', 0);
    }

    #[Test]
    public function the_panels_editor_leaves_the_cleaning_to_the_column(): void
    {
        // The pairing the cast depends on. Nova's Trix sanitizes by default, with an allowlist of
        // its own; leaving that on would be a second rule for one of the two doors, and the day
        // they differed the panel and the API would keep different markup under one name.
        $field = RichText::make('Omschrijving', 'description');

        $this->assertFalse($field->sanitizesHtml);

        // And no attachments, which would be a file with no row behind it (rules 12 and 13).
        $this->assertFalse($field->withFiles);
    }

    #[Test]
    public function the_step_form_offers_the_editor_inside_its_repeater(): void
    {
        // Nova's repeater draws a repeatable's fields itself, and the block body is the one prose
        // field that lives inside one. Asserting the field class alone would not notice the day a
        // preset stopped handing it through, and the form would answer 200 with a plain input.
        $this->signedInOperator();

        $fields = $this->getJson('/nova-api/steps/creation-fields')->assertOk()->json('fields');

        $this->assertIsArray($fields);

        $editors = self::editorsIn($fields);

        $this->assertCount(1, $editors);
        $this->assertSame('body', $editors[0]['attribute']);
        $this->assertTrue($editors[0]['shouldShow']);
        $this->assertFalse($editors[0]['withFiles']);
    }

    #[Test]
    public function cleaning_is_idempotent(): void
    {
        // A row read and written again must not decay: every save runs the cast over what the last
        // one stored, and content is edited far more often than it is created.
        $this->assertSame(self::CLEAN, SanitizedHtml::clean(self::CLEAN));
    }

    /**
     * Every editor anywhere in a serialized form, however deeply a repeater nests it.
     *
     * @param  array<mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private static function editorsIn(array $fields): array
    {
        $found = [];

        foreach ($fields as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (($value['component'] ?? null) === 'trix-field') {
                $found[] = $value;
            }

            $found = [...$found, ...self::editorsIn($value)];
        }

        return $found;
    }

    /**
     * @param  TestResponse<\Illuminate\Http\Response>  $response
     * @return list<string>|null
     */
    private function errorsFor(TestResponse $response, string $field): ?array
    {
        $errors = $response->json('errors');

        return is_array($errors) && is_array($errors[$field] ?? null) ? array_values($errors[$field]) : null;
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create();
    }

    /** The panel signs somebody in the only way it can: by spending a link (rule 19). */
    private function signedInOperator(): User
    {
        config(['session.driver' => 'database']);

        $user = $this->platformAdministrator();
        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->post(route('nova.sign-in.redeem'), ['token' => $secret->value])
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
