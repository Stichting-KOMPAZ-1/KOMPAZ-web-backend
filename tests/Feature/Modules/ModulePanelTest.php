<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Enums\ModuleStatus;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\ModuleContact;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\User;
use App\Nova\Actions\AssignModule;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The panel's two views of a module, driven through Nova's own HTTP API.
 *
 * A platform administrator writes modules; an organization administrator fills in their own copy
 * of one. They are two resources over two tables on purpose, and the tests that matter most here
 * are the ones about which of them each operator gets — a tenant reaching the authoring resource
 * would be writing what every other organization reads.
 */
final class ModulePanelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_platform_can_create_a_module_through_the_panel(): void
    {
        // Rule 18's exception, exercised: Nova's own form writes this one.
        $this->signedInOperator();
        $category = ModuleCategory::factory()->named('Medicatie')->create();

        $this->postJson('/nova-api/modules', [
            'name' => 'Subcutaan Injecteren',
            'category_id' => (string) $category->getKey(),
            'description' => 'Hoe je medicijnen onder de huid prikt.',
            'status' => ModuleStatus::Available->value,
        ])->assertSuccessful();

        $module = Module::query()->where('name', 'Subcutaan Injecteren')->sole();

        $this->assertSame(ModuleStatus::Available, $module->status);
        $this->assertNull($module->image());
    }

    #[Test]
    public function a_module_can_be_created_without_a_picture(): void
    {
        // The case that is easy to lose: the form has an upload on it, and the column is nullable
        // precisely because some modules have none.
        $this->signedInOperator();
        $category = ModuleCategory::factory()->create();

        $this->postJson('/nova-api/modules', [
            'name' => 'Oogdruppels Toedienen',
            'category_id' => (string) $category->getKey(),
            'description' => 'Druppelen zonder het oog aan te raken.',
            'status' => ModuleStatus::InDevelopment->value,
        ])->assertSuccessful();

        $this->assertDatabaseCount('modules', 1);
    }

    #[Test]
    public function a_module_without_a_name_is_refused(): void
    {
        $this->signedInOperator();
        $category = ModuleCategory::factory()->create();

        $this->postJson('/nova-api/modules', [
            'name' => '',
            'category_id' => (string) $category->getKey(),
            'description' => 'Zonder naam.',
            'status' => ModuleStatus::Available->value,
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->assertDatabaseCount('modules', 0);
    }

    #[Test]
    public function assigning_through_the_panel_reaches_the_use_case(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();
        $organization = Organization::factory()->create();

        $this->post(
            '/nova-api/modules/action?action='.app(AssignModule::class)->uriKey(),
            [
                'resources' => (string) $module->getKey(),
                // A BooleanGroup carries its answer as a JSON object rather than a form array,
                // which is what its own field reads back with json_decode().
                'organizations' => (string) json_encode([(string) $organization->getKey() => true]),
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertNotNull($module->activationFor((string) $organization->getKey()));
    }

    #[Test]
    public function the_detail_page_shows_who_has_the_module(): void
    {
        // The table loaded the count and the detail page did not, so a module assigned to an
        // organization read as "0 organisaties" as soon as it was opened.
        $this->signedInOperator();
        $module = Module::factory()->create();
        foreach (Organization::factory()->count(2)->create() as $organization) {
            ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();
        }
        Organization::factory()->create();

        $fields = $this->getJson("/nova-api/modules/{$module->getKey()}")
            ->assertOk()
            ->json('resource.fields');

        $this->assertIsArray($fields);

        $reach = null;

        foreach ($fields as $field) {
            if (($field['name'] ?? null) === 'Actief bij') {
                $reach = $field;
            }
        }

        $this->assertNotNull($reach, 'The module detail page has no "Actief bij".');
        $this->assertSame('2 organisaties', $reach['value'] ?? null);
    }

    #[Test]
    public function an_organization_administrator_cannot_reach_the_authoring_resource(): void
    {
        // The one that would matter most: writing here is writing what every organization reads.
        $this->signedInAdministrator();

        $this->getJson('/nova-api/modules')->assertForbidden();
    }

    #[Test]
    public function an_organization_administrator_sees_only_their_own_copy(): void
    {
        $admin = $this->signedInAdministrator();
        $module = Module::factory()->create();

        $mine = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($admin->organization)->create();
        $theirs = ModuleActivation::factory()->ofModule($module)->create();

        $listed = $this->listedIdentifiers('/nova-api/module-activations');

        $this->assertContains((string) $mine->getKey(), $listed);
        $this->assertNotContains((string) $theirs->getKey(), $listed);
    }

    #[Test]
    public function another_organizations_copy_cannot_be_opened_by_its_key(): void
    {
        // A detail page is reached by typing a key as readily as by clicking a row.
        $this->signedInAdministrator();
        $theirs = ModuleActivation::factory()->create();

        $this->getJson('/nova-api/module-activations/'.$theirs->getKey())
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function an_organization_administrator_cannot_create_or_delete_their_copy(): void
    {
        // A module arrives because the platform switched it on, and leaving is the platform taking
        // it away. Neither is a button on their side.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()
            ->forOrganization($admin->organization)->create();

        $this->postJson('/nova-api/module-activations', [])->assertForbidden();

        // Nova's delete controller skips the records an operator may not delete and still answers
        // 200, so what proves the refusal is the row, not the status.
        $this->deleteJson('/nova-api/module-activations', [
            'resources' => [(string) $activation->getKey()],
        ]);

        $this->assertModelExists($activation);
    }

    #[Test]
    public function an_organization_administrator_fills_in_their_own_copy(): void
    {
        // What the copy is for: once a module is assigned, the organization adds its own people.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()
            ->forOrganization($admin->organization)->create();

        $this->putJson('/nova-api/module-activations/'.$activation->getKey(), [
            'contacts' => [$this->contactRow('Petra de Vries', '0201234567')],
        ])->assertOk();

        $this->assertSame(['Petra de Vries'], $activation->contacts()->pluck('name')->all());
    }

    #[Test]
    public function another_organizations_copy_cannot_be_written_by_its_key(): void
    {
        // The detail page was scoped and the edit was not: Nova finds the record an edit is saved
        // onto without `detailQuery`, and with no policy it asks nothing else. This is rule 22's
        // case exactly — one organization writing the phone number another one shows.
        $this->signedInAdministrator();
        $theirs = ModuleActivation::factory()->create();
        ModuleContact::factory()->ofActivation($theirs)->create(['name' => 'Hun contactpersoon']);

        $this->getJson('/nova-api/module-activations/'.$theirs->getKey().'/update-fields')
            ->assertForbidden();

        $this->putJson('/nova-api/module-activations/'.$theirs->getKey(), [
            'contacts' => [$this->contactRow('Iemand anders', '0600000000')],
        ])->assertForbidden();

        $this->assertSame(['Hun contactpersoon'], $theirs->contacts()->pluck('name')->all());
    }

    #[Test]
    public function a_contact_cannot_be_reached_through_its_own_resource(): void
    {
        // The resource exists only because a repeater needs one. Addressed directly it has no
        // owner to ask, so it answers no to everything.
        $this->signedInAdministrator();
        $contact = ModuleContact::factory()->create();

        $this->getJson('/nova-api/module-contacts/'.$contact->getKey())->assertForbidden();

        $this->deleteJson('/nova-api/module-contacts', ['resources' => [(string) $contact->getKey()]]);

        $this->assertModelExists($contact);
    }

    #[Test]
    public function an_organization_administrator_cannot_open_or_edit_the_platforms_module(): void
    {
        // Refusing the listing hid the table; without a policy, Nova still opened and saved a
        // module reached by its key.
        $this->signedInAdministrator();
        $module = Module::factory()->create(['name' => 'Van het platform']);

        $this->getJson('/nova-api/modules/'.$module->getKey())->assertForbidden();

        $this->putJson('/nova-api/modules/'.$module->getKey(), [
            'name' => 'Overschreven',
            'category_id' => (string) $module->category_id,
            'description' => $module->description,
            'status' => $module->status->value,
        ])->assertForbidden();

        $this->assertSame('Van het platform', $module->fresh()?->name);
    }

    #[Test]
    public function the_contacts_column_reports_whether_the_organization_filled_it_in(): void
    {
        // The one column the organization administrator's table exists to draw attention to.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()
            ->forOrganization($admin->organization)->create();

        $this->assertFalse($activation->hasContactDetails());

        ModuleContact::factory()->ofActivation($activation)->create();

        $this->assertTrue($activation->fresh()?->hasContactDetails());
    }

    #[Test]
    public function the_platform_does_not_get_the_tenants_view_and_the_tenant_does_not_get_the_platforms(): void
    {
        // Two menu entries both called "Modules" would be two rows that disagree about what a row
        // is, so each operator is shown exactly one of them.
        $this->signedInOperator();
        $this->getJson('/nova-api/module-activations')->assertForbidden();
        $this->getJson('/nova-api/modules')->assertOk();
    }

    #[Test]
    public function the_platforms_own_videos_and_a_tenants_stay_apart(): void
    {
        $admin = $this->signedInAdministrator();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($admin->organization)->create();

        ModuleVideo::factory()->ofModule($module)->create(['title' => 'Van het platform']);
        ModuleVideo::factory()->ofActivation($activation)->create(['title' => 'Van ons']);

        // The repeater on their page is fed by the activation's relation, never the module's.
        $this->assertSame(['Van ons'], $activation->videos()->pluck('title')->all());
        $this->assertSame(['Van het platform'], $module->videos()->pluck('title')->all());
    }

    /** @return array<string, mixed> */
    private function contactRow(string $name, string $phone): array
    {
        return ['type' => 'module-contact-repeatable', 'fields' => ['name' => $name, 'phone' => $phone]];
    }

    /** @return list<string> */
    private function listedIdentifiers(string $url): array
    {
        $resources = $this->getJson($url)->assertOk()->json('resources');

        if (! is_array($resources)) {
            self::fail('Nova listed no resources at all.');
        }

        $found = [];

        foreach ($resources as $resource) {
            if (is_array($resource) && is_string($resource['id']['value'] ?? null)) {
                $found[] = $resource['id']['value'];
            }
        }

        return $found;
    }

    private function signedInAdministrator(): User
    {
        return $this->signInThroughLink(
            User::factory()->administrator()->for(Organization::factory())->create(),
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

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }
}
