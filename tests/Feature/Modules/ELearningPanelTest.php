<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Nova\Actions\AssignModulesToOrganization;
use App\Nova\Actions\DeleteELearning;
use App\Services\SecretTokenFactory;
use App\Support\Modules\ModuleMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The two things the panel could not do, and the dialog that could not show what it knew.
 *
 * All three were reported from the panel rather than found by a test, which is the common thread:
 * each one is about what an operator is *offered*, and every test written so far asked whether an
 * operation worked once it had been reached.
 */
final class ELearningPanelTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    #[Test]
    public function the_create_and_edit_forms_open(): void
    {
        // The test that was missing. Posting to a form exercises the writing; it does not exercise
        // Nova *building* it, and that is where this broke — the picture's preview built a URL for
        // a record that does not exist yet, which is a 500 on the form rather than a missing
        // thumbnail.
        $this->signedInOperator();

        $this->getJson('/nova-api/e-learnings/creation-fields')->assertOk();

        $course = ELearning::factory()->create();

        $this->getJson("/nova-api/e-learnings/{$course->getKey()}/update-fields")->assertOk();
    }

    #[Test]
    public function the_modules_form_opens_too(): void
    {
        $this->signedInOperator();

        $this->getJson('/nova-api/modules/creation-fields')->assertOk();

        $module = Module::factory()->create();

        $this->getJson("/nova-api/modules/{$module->getKey()}/update-fields")->assertOk();
    }

    #[Test]
    public function a_course_can_be_created_from_the_panel(): void
    {
        $this->signedInOperator();

        $this->post('/nova-api/e-learnings', [
            'name' => 'Medicijnen prikken onder de huid',
            'image_storage_key' => UploadedFile::fake()->createWithContent('cover.png', self::PNG),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $course = ELearning::query()->where('name', 'Medicijnen prikken onder de huid')->sole();

        // The picture is not optional here, unlike a module's, and the three columns are whole.
        $this->assertSame('image/png', $course->image_content_type);
        $this->assertStringStartsWith('e-learnings/'.$course->getKey().'/', $course->image_storage_key);
    }

    #[Test]
    public function a_course_without_a_picture_is_refused(): void
    {
        // The columns behind it are not nullable, so this has to be caught by the form rather than
        // by the database.
        $this->signedInOperator();

        $this->postJson('/nova-api/e-learnings', ['name' => 'Zonder afbeelding'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('image_storage_key');

        $this->assertDatabaseCount('e_learnings', 0);
    }

    #[Test]
    public function a_course_can_be_renamed_without_uploading_its_picture_again(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create(['name' => 'Oude naam']);
        $original = $course->image_storage_key;

        $this->put("/nova-api/e-learnings/{$course->getKey()}", [
            'name' => 'Nieuwe naam',
            '_method' => 'PUT',
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $reloaded = $course->fresh();

        $this->assertNotNull($reloaded);
        $this->assertSame('Nieuwe naam', $reloaded->name);
        $this->assertSame($original, $reloaded->image_storage_key);
    }

    #[Test]
    public function deleting_a_course_carries_the_sentence_the_product_wrote(): void
    {
        $this->assertSame(
            'Weet je zeker dat je deze e-learning wilt verwijderen? '
            .'De e-learning wordt volledig uit het systeem gehaald en zal niet zichtbaar meer zijn voor organisaties. '
            .'Herstellen is daarna niet meer mogelijk. '
            .'Mogelijke gelinkte modules worden hierbij NIET verwijderd.',
            ModuleMessages::DELETE_E_LEARNING_CONFIRMATION,
        );
    }

    #[Test]
    public function deleting_a_course_keeps_the_modules_that_showed_it(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        $module = Module::factory()->create();
        $module->eLearnings()->attach($course);

        $chapter = Chapter::factory()->of($course)->create();
        ContentBlock::factory()->of(Step::factory()->of($chapter)->create())->create();

        $this->post(
            '/nova-api/e-learnings/action?action='.app(DeleteELearning::class)->uriKey(),
            ['resources' => (string) $course->getKey()],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertModelMissing($course);
        $this->assertModelExists($module);
        $this->assertDatabaseCount('chapters', 0);
        $this->assertDatabaseCount('content_blocks', 0);
    }

    #[Test]
    public function an_organization_administrator_cannot_author_a_course(): void
    {
        $this->signedInAdministrator();

        $this->getJson('/nova-api/e-learnings')->assertForbidden();
        $this->postJson('/nova-api/e-learnings', ['name' => 'Van ons'])->assertForbidden();
    }

    #[Test]
    public function modules_can_be_assigned_from_the_organizations_own_page(): void
    {
        // The direction that was missing: an operator setting up a tenant is holding the tenant.
        $this->signedInOperator();
        $organization = Organization::factory()->create();
        $module = Module::factory()->create();

        $this->post(
            '/nova-api/organizations/action?action='.app(AssignModulesToOrganization::class)->uriKey(),
            [
                'resources' => (string) $organization->getKey(),
                'modules' => (string) json_encode([(string) $module->getKey() => true]),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertNotNull($module->activationFor((string) $organization->getKey()));
    }

    #[Test]
    public function assigning_from_that_side_keeps_the_date_an_existing_module_was_given(): void
    {
        // The same rule as the module's own side, because it is the same use case.
        $this->signedInOperator();
        $organization = Organization::factory()->create();
        $kept = Module::factory()->create();
        $added = Module::factory()->create();

        $yesterday = Carbon::now()->subDay();
        $original = ModuleActivation::factory()
            ->ofModule($kept)->forOrganization($organization)->create(['activated_at' => $yesterday]);

        $this->post(
            '/nova-api/organizations/action?action='.app(AssignModulesToOrganization::class)->uriKey(),
            [
                'resources' => (string) $organization->getKey(),
                'modules' => (string) json_encode([
                    (string) $kept->getKey() => true,
                    (string) $added->getKey() => true,
                ]),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $still = $kept->activationFor((string) $organization->getKey());

        $this->assertNotNull($still);
        $this->assertSame($original->getKey(), $still->getKey());
        $this->assertSame($yesterday->format('Y-m-d H:i:s'), $still->activated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_assignment_dialogs_open_on_what_is_already_true(): void
    {
        // The reported bug. Both dialogs used to open with every box empty, whatever was actually
        // assigned — so an operator could not see the current state, and saving after ticking one
        // box silently removed everything they had not realised was there.
        $this->signedInOperator();
        $organization = Organization::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        $fromTheModule = $this->actionFieldValue('modules', (string) $module->getKey(), 'organizations');
        $this->assertSame(true, $fromTheModule[(string) $organization->getKey()] ?? null);

        $fromTheOrganization = $this->actionFieldValue('organizations', (string) $organization->getKey(), 'modules');
        $this->assertSame(true, $fromTheOrganization[(string) $module->getKey()] ?? null);
    }

    #[Test]
    public function an_unassigned_pairing_opens_unticked(): void
    {
        $this->signedInOperator();
        $organization = Organization::factory()->create();
        $module = Module::factory()->create();

        $selection = $this->actionFieldValue('modules', (string) $module->getKey(), 'organizations');

        $this->assertSame(false, $selection[(string) $organization->getKey()] ?? null);
    }

    /**
     * The value a field opens with, as Nova serializes the dialog.
     *
     * @return array<string, bool>
     */
    private function actionFieldValue(string $resource, string $resourceId, string $attribute): array
    {
        $actions = $this->getJson("/nova-api/{$resource}/actions?resourceId={$resourceId}")
            ->assertOk()
            ->json('actions');

        $this->assertIsArray($actions);

        foreach ($actions as $action) {
            foreach ($action['fields'] ?? [] as $field) {
                if (($field['attribute'] ?? null) === $attribute) {
                    /** @var array<string, bool> $value */
                    $value = $field['value'] ?? [];

                    return $value;
                }
            }
        }

        self::fail("The panel did not offer a {$attribute} field on {$resource}.");
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
