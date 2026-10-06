<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\LoginTokenPurpose;
use App\Enums\ModuleStatus;
use App\Models\Chapter;
use App\Models\ELearning;
use App\Models\LoginToken;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleCategory;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Nova\Actions\AssignModule;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function the_platform_can_create_a_module_through_the_panel(): void
    {
        // Rule 18's exception, exercised: Nova's own form writes this one.
        $this->signedInOperator();
        $category = ModuleCategory::factory()->named('Medicatie')->create();

        $this->post('/nova-api/modules', [
            'name' => 'Subcutaan Injecteren',
            'category_id' => (string) $category->getKey(),
            'description' => 'Hoe je medicijnen onder de huid prikt.',
            'status' => ModuleStatus::Available->value,
            'image_storage_key' => UploadedFile::fake()->createWithContent('cover.png', self::PNG),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $module = Module::query()->where('name', 'Subcutaan Injecteren')->sole();

        $this->assertSame(ModuleStatus::Available, $module->status);
        $this->assertSame('image/png', $module->image_content_type);
        $this->assertStringStartsWith('modules/'.$module->getKey().'/', (string) $module->image_storage_key);
    }

    #[Test]
    public function a_module_without_a_picture_is_refused(): void
    {
        // KOM-41 makes the picture required. The columns stay nullable for modules written before
        // that, so this is the form's to catch and not the database's.
        $this->signedInOperator();
        $category = ModuleCategory::factory()->create();

        $this->postJson('/nova-api/modules', [
            'name' => 'Oogdruppels Toedienen',
            'category_id' => (string) $category->getKey(),
            'description' => 'Druppelen zonder het oog aan te raken.',
            'status' => ModuleStatus::InDevelopment->value,
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('image_storage_key');

        $this->assertDatabaseCount('modules', 0);
    }

    #[Test]
    public function a_module_can_be_edited_without_uploading_its_picture_again(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();
        $original = $module->image_storage_key;

        $this->put("/nova-api/modules/{$module->getKey()}", [
            'name' => 'Nieuwe naam',
            'category_id' => (string) $module->category_id,
            'description' => 'Een nieuwe omschrijving.',
            'status' => ModuleStatus::Available->value,
            '_method' => 'PUT',
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame($original, $module->fresh()?->image_storage_key);
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
    public function the_module_table_has_the_columns_kom_40_asks_for(): void
    {
        $this->signedInOperator();
        Module::factory()->create();

        $row = $this->getJson('/nova-api/modules')->assertOk()->json('resources.0.fields');

        $this->assertIsArray($row);
        $this->assertSame(['Naam', 'Categorie', 'Actief bij', 'Status'], array_column($row, 'name'));
    }

    #[Test]
    public function both_pickers_on_the_module_form_are_searchable_lists_filled_in_with_what_is_linked(): void
    {
        // KOM-41 asks for search, select all and deselect all, which Nova's own fields lack.
        $this->signedInOperator();
        $module = Module::factory()->create();
        $linked = ELearning::factory()->create();
        $other = ELearning::factory()->create();
        $module->eLearnings()->attach($linked);

        $fields = $this->getJson("/nova-api/modules/{$module->getKey()}/update-fields")
            ->assertOk()
            ->json('fields');

        $this->assertIsArray($fields);

        $byAttribute = array_column($fields, null, 'attribute');
        $courses = $byAttribute['linked_e_learnings'] ?? null;
        $organizations = $byAttribute['active_organizations'] ?? null;

        $this->assertIsArray($courses);
        $this->assertIsArray($organizations);
        $this->assertSame('checkbox-list', $courses['component']);
        $this->assertSame('checkbox-list', $organizations['component']);
        $this->assertSame('Alles selecteren', $courses['selectAllLabel']);
        $this->assertTrue($courses['value'][(string) $linked->getKey()]);
        $this->assertFalse($courses['value'][(string) $other->getKey()]);
    }

    #[Test]
    public function the_module_form_links_the_ticked_courses_and_unlinks_the_rest(): void
    {
        $this->signedInOperator();
        $module = Module::factory()->create();
        $keep = ELearning::factory()->create();
        $drop = ELearning::factory()->create();
        $add = ELearning::factory()->create();
        $module->eLearnings()->attach([$keep->getKey(), $drop->getKey()]);

        $this->putJson("/nova-api/modules/{$module->getKey()}", [
            'name' => $module->name,
            'category_id' => (string) $module->category_id,
            'description' => $module->description,
            'status' => $module->status->value,
            // A course deleted while the form was open is dropped rather than linked.
            'linked_e_learnings' => (string) json_encode([
                (string) $keep->getKey() => true,
                (string) $drop->getKey() => false,
                (string) $add->getKey() => true,
                '01a0ece5-0000-7000-8000-000000000000' => true,
            ]),
        ])->assertOk();

        $linked = $module->eLearnings()->pluck('e_learnings.id')->all();
        sort($linked);
        $expected = [(string) $keep->getKey(), (string) $add->getKey()];
        sort($expected);

        $this->assertSame($expected, $linked);
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

    #[Test]
    public function a_modules_own_page_shows_its_courses_videos_and_links(): void
    {
        // KOM-40's read-only view. `asHasMany()` makes a repeater form-only and the course picker
        // was a form field, so the page showed none of the three.
        $this->signedInOperator();
        $module = Module::factory()->create();
        $module->eLearnings()->attach(ELearning::factory()->create(['name' => 'Medicijnen prikken']));
        ModuleVideo::factory()->ofModule($module)->create(['title' => 'Zo prik je']);
        ModuleLink::factory()->ofModule($module)->create(['title' => 'Bijsluiter']);

        $fields = $this->detailFields('/nova-api/modules/'.$module->getKey());

        $this->assertSame('Medicijnen prikken', $fields['E-learnings']['value'] ?? null);
        $this->assertIsArray($fields["Video's"]['value'] ?? null);
        $this->assertCount(1, $fields["Video's"]['value']);
        $this->assertIsArray($fields['Extra links']['value'] ?? null);
        $this->assertCount(1, $fields['Extra links']['value']);
    }

    #[Test]
    public function the_table_is_newest_first_until_a_column_is_clicked(): void
    {
        // The default order was stated in indexQuery, and Nova adds a clicked column *after* what
        // that query already orders by — so the header sorted nothing.
        $this->signedInOperator();
        $older = Module::factory()->create(['name' => 'Aambeien', 'created_at' => Carbon::parse('2026-01-01')]);
        $newer = Module::factory()->create(['name' => 'Zalf smeren', 'created_at' => Carbon::parse('2026-06-01')]);

        $this->assertSame(
            [(string) $newer->getKey(), (string) $older->getKey()],
            $this->listedIdentifiers('/nova-api/modules'),
        );

        $this->assertSame(
            [(string) $older->getKey(), (string) $newer->getKey()],
            $this->listedIdentifiers('/nova-api/modules?orderBy=name&orderByDirection=asc'),
        );
    }

    #[Test]
    public function an_organization_administrator_reads_the_description_as_the_platform_wrote_it(): void
    {
        // Plain text: whatever looks like a tag in it is part of the sentence, not something to
        // draw. Nova's Textarea escapes it for the page, which is what shows it as typed.
        $admin = $this->signedInAdministrator();
        $module = Module::factory()->create(['description' => "Prik <langzaam>.\n\nEn wacht."]);
        $activation = ModuleActivation::factory()->ofModule($module)->forOrganization($admin->organization)->create();

        $description = $this->detailFields('/nova-api/module-activations/'.$activation->getKey())['Omschrijving'] ?? null;

        $this->assertIsArray($description);
        $this->assertSame('textarea-field', $description['component']);
        $this->assertSame("Prik &lt;langzaam&gt;.\n\nEn wacht.", $description['value']);
    }

    #[Test]
    public function a_contact_card_added_and_left_empty_is_left_out(): void
    {
        // KOM-73, on the organization administrator's form: "+" pressed once too often.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()->forOrganization($admin->organization)->create();

        $this->putJson('/nova-api/module-activations/'.$activation->getKey(), [
            'contacts' => [
                $this->contactRow('Petra de Vries', '0201234567'),
                ['type' => 'module-contact-repeatable', 'fields' => ['name' => '', 'job_role' => '', 'email' => '', 'phone' => '']],
            ],
            'links' => [
                ['type' => 'module-link-repeatable', 'fields' => ['title' => 'Ons protocol', 'url' => 'www.voorbeeld.nl/protocol']],
                ['type' => 'module-link-repeatable', 'fields' => ['title' => '', 'url' => '']],
            ],
            // The address the ticket was tested with.
            'videos' => [
                ['type' => 'module-video-repeatable', 'fields' => ['title' => 'Uitleg', 'url' => 'www.youtube.com/video']],
            ],
        ])->assertOk();

        $this->assertSame(['Petra de Vries'], $activation->contacts()->pluck('name')->all());
        $this->assertSame(['https://www.voorbeeld.nl/protocol'], $activation->links()->pluck('url')->all());
        $this->assertSame(['https://www.youtube.com/video'], $activation->videos()->pluck('url')->all());
    }

    #[Test]
    public function half_a_contact_card_is_still_pointed_out(): void
    {
        // Only a row with nothing in it is left out. One with a name and no e-mail address is a
        // mistake worth showing.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()->forOrganization($admin->organization)->create();

        $this->putJson('/nova-api/module-activations/'.$activation->getKey(), [
            'contacts' => [['type' => 'module-contact-repeatable', 'fields' => ['name' => 'Petra de Vries', 'job_role' => '', 'email' => '']]],
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['contacts.0.fields.email', 'contacts.0.fields.job_role']);

        $this->assertSame(0, $activation->contacts()->count());
    }

    #[Test]
    public function no_table_draws_a_pencil_and_the_rows_menu_opens_the_form_instead(): void
    {
        // KOM-42: a table's operations are in its "…" menu and nowhere else. Nova draws the pencil
        // from what the listing says about each row, so the listing says no — and only the listing.
        $this->signedInOperator();
        $module = Module::factory()->create();

        $row = $this->getJson('/nova-api/modules')->assertOk()->json('resources.0');

        $this->assertIsArray($row);
        $this->assertFalse($row['authorizedToUpdate']);
        $this->assertIsArray($row['actions']);
        $this->assertSame('Bewerken', $row['actions'][0]['name']);

        // The module's own page keeps its edit button, and the form still opens and saves.
        $this->getJson('/nova-api/modules/'.$module->getKey())
            ->assertOk()
            ->assertJsonPath('resource.authorizedToUpdate', true);
        $this->getJson('/nova-api/modules/'.$module->getKey().'/update-fields')->assertOk();

        $this->post(
            '/nova-api/modules/action?action='.$row['actions'][0]['uriKey'],
            ['resources' => (string) $module->getKey()],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('visit.path', '/resources/modules/'.$module->getKey().'/edit');
    }

    #[Test]
    public function a_courses_chapters_and_a_chapters_parts_are_edited_from_the_menu_too(): void
    {
        // These two had no menu at all, only Nova's pencil and trash can.
        $this->signedInOperator();
        $course = ELearning::factory()->create();
        $chapter = Chapter::factory()->of($course)->create();
        Step::factory()->of($chapter)->create();

        foreach ([
            '/nova-api/chapters?viaResource=e-learnings&viaResourceId='.$course->getKey().'&viaRelationship=chapters&relationshipType=hasMany',
            '/nova-api/steps?viaResource=chapters&viaResourceId='.$chapter->getKey().'&viaRelationship=steps&relationshipType=hasMany',
        ] as $listing) {
            $row = $this->getJson($listing)->assertOk()->json('resources.0');

            $this->assertIsArray($row, $listing);
            $this->assertFalse($row['authorizedToUpdate'], $listing);
            $this->assertIsArray($row['actions'], $listing);
            $this->assertSame(['Bewerken'], array_column($row['actions'], 'name'), $listing);
        }
    }

    #[Test]
    public function an_organizations_copy_is_completed_from_the_menu_and_never_edited(): void
    {
        // Its one row operation stays "Informatie aanvullen": an organization cannot edit the
        // module, so "Bewerken" would be the wrong word, and the pencil goes as everywhere else.
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()->forOrganization($admin->organization)->create();

        $row = $this->getJson('/nova-api/module-activations')->assertOk()->json('resources.0');

        $this->assertIsArray($row);
        $this->assertFalse($row['authorizedToUpdate']);
        $this->assertIsArray($row['actions']);
        $this->assertSame(['Informatie aanvullen'], array_column($row['actions'], 'name'));

        // It opens the copy's own edit form, as "Bewerken" does a module's.
        $this->post(
            '/nova-api/module-activations/action?action='.$row['actions'][0]['uriKey'],
            ['resources' => (string) $activation->getKey()],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('visit.path', '/resources/module-activations/'.$activation->getKey().'/edit');
    }

    /** @return array<string, mixed> */
    private function contactRow(string $name, string $phone): array
    {
        return ['type' => 'module-contact-repeatable', 'fields' => [
            'name' => $name,
            'job_role' => 'Wondverpleegkundige',
            'email' => 'contact@example.nl',
            'phone' => $phone,
        ]];
    }

    /**
     * The fields a detail page draws, by the name an operator reads above each one.
     *
     * @return array<string, array<mixed>>
     */
    private function detailFields(string $url): array
    {
        $fields = $this->getJson($url)->assertOk()->json('resource.fields');

        if (! is_array($fields)) {
            self::fail('Nova drew no fields at all.');
        }

        $byName = [];

        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $byName[$field['name']] = $field;
            }
        }

        return $byName;
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

        $this->post(route('nova.sign-in.redeem'), ['token' => $secret->value])
            ->assertRedirect(config('nova.path'));

        return $user->refresh();
    }

    /**
     * Nova's pencil says "Bewerken" for every resource in the panel and takes no per-resource
     * label, and for this one it is wrong twice over: an organization administrator cannot change
     * the module, only add their own videos, links and contacts to their copy of it.
     */
    #[Test]
    public function an_organization_is_offered_completing_the_information_rather_than_editing(): void
    {
        $admin = $this->signedInAdministrator();
        $activation = ModuleActivation::factory()
            ->for(Module::factory())
            ->for($admin->organization)
            ->create();

        $actions = $this->getJson(
            '/nova-api/module-activations/actions?resourceId='.$activation->getKey(),
        )->assertOk()->json('actions');

        $this->assertIsArray($actions);
        $names = array_column($actions, 'name');

        $this->assertContains('Informatie aanvullen', $names);
    }

    /** The form it opens on says the same thing, rather than Nova's "Update :resource". */
    #[Test]
    public function the_form_it_opens_is_labelled_the_same_way(): void
    {
        $this->assertSame(
            'Informatie aanvullen',
            \App\Nova\ModuleActivation::updateButtonLabel(),
        );
    }

    /**
     * An organization's own page for a module is a `ModuleActivation`, and that row has no name of
     * its own — the name is the module's, across the relation. Nova names a row from a column and
     * fell back to the key, so every heading and breadcrumb on the page an organization
     * administrator opens read as a UUID.
     */
    #[Test]
    public function an_organizations_module_page_is_named_after_the_module(): void
    {
        $module = Module::factory()->create(['name' => 'Omgaan met stress']);
        $activation = ModuleActivation::factory()->for($module)->create();

        $resource = new \App\Nova\ModuleActivation($activation);

        $this->assertSame('Omgaan met stress', $resource->title());
        $this->assertNotSame((string) $activation->getKey(), $resource->title());
    }
}
