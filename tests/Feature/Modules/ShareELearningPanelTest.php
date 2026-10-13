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
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
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
    public function only_the_organizations_the_course_is_shared_with_can_be_chosen(): void
    {
        $this->signedInOperator();
        [$organization, $course] = $this->shared('Regio Midden');
        Organization::factory()->create(['name' => 'Zonder deze module']);

        $actions = $this->getJson('/nova-api/e-learnings/actions?resourceId='.$course->getKey().'&display=detail')
            ->assertOk()
            ->json('actions');

        $this->assertIsArray($actions);
        $share = collect($actions)->firstWhere('name', 'Delen');
        $this->assertIsArray($share);

        $this->assertSame(
            [['label' => 'Regio Midden', 'value' => (string) $organization->getKey()]],
            array_map(
                static fn (array $option): array => ['label' => $option['label'], 'value' => $option['value']],
                $share['fields'][0]['options'],
            ),
        );
    }

    #[Test]
    public function sharing_answers_with_the_link_and_the_qr_code_in_the_panels_own_dialog(): void
    {
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        $this->signedInOperator();
        [$organization, $course] = $this->shared('Regio Midden', 'Steunkousen');

        $modal = $this->post('/nova-api/e-learnings/action?action='.app(ShareELearning::class)->uriKey(), [
            'resources' => (string) $course->getKey(),
            'organization' => (string) $organization->getKey(),
        ], ['Accept' => 'application/json'])->assertOk()->json('modal');

        $this->assertIsArray($modal);
        $this->assertSame(ShareELearning::MODAL, $modal['component']);
        $this->assertSame('https://kompaz.test/regio-midden/steunkousen', $modal['payload']['link']);
        $this->assertSame('E-learning link gekopieerd!', $modal['payload']['labels']['copied']);
        $this->assertSame(
            route('nova.share-qr-code', ['eLearning' => $course->getKey(), 'organization' => $organization->getKey()]).'?download=1',
            $modal['payload']['qrCodeDownloadUrl'],
        );
    }

    #[Test]
    public function an_organization_the_course_is_not_shared_with_is_refused(): void
    {
        $this->signedInOperator();
        [, $course] = $this->shared();
        $elsewhere = Organization::factory()->create();

        $this->post('/nova-api/e-learnings/action?action='.app(ShareELearning::class)->uriKey(), [
            'resources' => (string) $course->getKey(),
            'organization' => (string) $elsewhere->getKey(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('organization');
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

    /** @return array{Organization, ELearning} */
    private function shared(?string $organizationName = null, ?string $courseName = null): array
    {
        $organization = Organization::factory()->create(array_filter(['name' => $organizationName]));
        $course = ELearning::factory()->create(array_filter(['name' => $courseName]));
        $module = Module::factory()->create();

        $module->eLearnings()->attach($course);
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        return [$organization, $course];
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
