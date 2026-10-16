<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\ShareELearning;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Delen" on an e-learning in the panel, and the QR code it offers (KOM-95). */
final class ShareELearningPanelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_e_learning_row_offers_sharing_in_its_menu(): void
    {
        $this->signedInOperator();
        ELearning::factory()->create();

        $row = $this->getJson('/nova-api/e-learnings')->assertOk()->json('resources.0');

        $this->assertIsArray($row);
        $this->assertIsArray($row['actions']);
        $this->assertContains('Delen', array_column($row['actions'], 'name'));
    }

    #[Test]
    public function sharing_opens_one_dialog_with_the_link_and_qr_code_of_every_organization_it_is_shared_with(): void
    {
        // KOM-95: the organization is picked in the dialog that shows the link, not in a dialog
        // before it — the link was looked for there and never appeared.
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        $this->signedInOperator();
        [$organization, $course, $module] = $this->shared('Regio Midden', 'Steunkousen');
        $second = Organization::factory()->create(['name' => 'Amsterdam UMC']);
        ModuleActivation::factory()->ofModule($module)->forOrganization($second)->create();
        Organization::factory()->create(['name' => 'Zonder deze module']);

        $modal = $this->runShare($course)->assertOk()->json('modal');

        $this->assertIsArray($modal);
        $this->assertSame(ShareELearning::MODAL, $modal['component']);
        $this->assertSame('Steunkousen', $modal['payload']['eLearning']);
        $this->assertSame('E-learning link gekopieerd!', $modal['payload']['labels']['copied']);

        // Only the organizations it is shared with, by name.
        $this->assertSame(['Amsterdam UMC', 'Regio Midden'], array_column($modal['payload']['organizations'], 'name'));

        $regioMidden = $modal['payload']['organizations'][1];
        $this->assertSame((string) $organization->getKey(), $regioMidden['id']);
        $this->assertSame('https://kompaz.test/delen/regio-midden/steunkousen', $regioMidden['link']);
        $this->assertSame(
            route('nova.share-qr-code', ['eLearning' => $course->getKey(), 'organization' => $organization->getKey()]).'?download=1',
            $regioMidden['qrCodeDownloadUrl'],
        );
    }

    #[Test]
    public function sharing_asks_nothing_before_the_dialog(): void
    {
        // No confirmation and no fields: the choice is made in the dialog itself.
        $this->signedInOperator();
        $course = ELearning::factory()->create();

        $actions = $this->getJson('/nova-api/e-learnings/actions?resourceId='.$course->getKey().'&display=detail')
            ->assertOk()
            ->json('actions');

        $this->assertIsArray($actions);
        $share = collect($actions)->firstWhere('name', 'Delen');
        $this->assertIsArray($share);
        $this->assertTrue($share['withoutConfirmation']);
        $this->assertSame([], $share['fields']);
    }

    #[Test]
    public function sharing_an_e_learning_ticked_in_the_table_works_as_from_its_row(): void
    {
        // Ticked in the table and shared from the menu above it, the request carries the selection
        // and no `resourceId`. The organization picker used to be built from `resourceId`, came out
        // empty, and the action then refused the empty choice.
        $this->signedInOperator();
        [, $course] = $this->shared('Regio Midden');

        $actions = $this->getJson('/nova-api/e-learnings/actions?display=index')->assertOk()->json('actions');
        $this->assertIsArray($actions);
        $this->assertContains('Delen', array_column($actions, 'name'));

        $this->post('/nova-api/e-learnings/action?action='.app(ShareELearning::class)->uriKey(), [
            'resources' => [(string) $course->getKey()],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('modal.payload.organizations.0.name', 'Regio Midden');
    }

    #[Test]
    public function an_e_learning_shared_nowhere_says_so_instead_of_opening_an_empty_dialog(): void
    {
        $this->signedInOperator();
        $course = ELearning::factory()->create();

        $this->runShare($course)
            ->assertOk()
            ->assertJsonPath('danger', 'Deze e-learning zit nog in geen module die bij een organisatie actief is.')
            ->assertJsonMissingPath('modal');
    }

    #[Test]
    public function the_qr_code_is_a_png_of_the_qr_address_shown_or_downloaded(): void
    {
        $this->signedInOperator();
        [$organization, $course] = $this->shared('Regio Midden', 'Steunkousen');
        $address = route('nova.share-qr-code', ['eLearning' => $course->getKey(), 'organization' => $organization->getKey()]);

        $shown = $this->get($address)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="qr-regio-midden-steunkousen.png"');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $shown->getContent());

        $this->get($address.'?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-regio-midden-steunkousen.png"');
    }

    #[Test]
    public function the_qr_code_is_the_platforms_and_only_for_an_organization_it_is_shared_with(): void
    {
        [$organization, $course] = $this->shared();
        $elsewhere = Organization::factory()->create();

        $this->signedInOperator();
        $this->get(route('nova.share-qr-code', ['eLearning' => $course->getKey(), 'organization' => $elsewhere->getKey()]))
            ->assertNotFound();

        $this->signInThroughLink(User::factory()->administrator()->for($organization)->create());
        $this->get(route('nova.share-qr-code', ['eLearning' => $course->getKey(), 'organization' => $organization->getKey()]))
            ->assertForbidden();
    }

    /** @return array{Organization, ELearning, Module} */
    private function shared(?string $organizationName = null, ?string $courseName = null): array
    {
        $organization = Organization::factory()->create(array_filter(['name' => $organizationName]));
        $course = ELearning::factory()->create(array_filter(['name' => $courseName]));
        $module = Module::factory()->create();

        $module->eLearnings()->attach($course);
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        return [$organization, $course, $module];
    }

    /**
     * Runs "Delen" on one e-learning, as its row's menu does.
     *
     * @return TestResponse<Response>
     */
    private function runShare(ELearning $course): TestResponse
    {
        return $this->post(
            '/nova-api/e-learnings/action?action='.app(ShareELearning::class)->uriKey(),
            ['resources' => (string) $course->getKey()],
            ['Accept' => 'application/json'],
        );
    }

    private function signedInOperator(): User
    {
        return $this->signInThroughLink(
            User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create(),
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

        $this->post(route('nova.sign-in.redeem'), ['token' => $secret->value])
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
