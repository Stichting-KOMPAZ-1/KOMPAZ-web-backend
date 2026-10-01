<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\UserRole;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\ModuleLink;
use App\Models\ModuleVideo;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read API a Zorgprofessional's application uses.
 *
 * The thing under test throughout is the tenant boundary, which for content is an activation rather
 * than an `organization_id`. A module nobody switched on for you does not exist as far as you are
 * concerned, and that has to be true of the listing, the detail and every file behind them.
 */
final class ModuleApiTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    #[Test]
    public function the_listing_shows_only_the_modules_switched_on_for_my_organization(): void
    {
        $member = User::factory()->create();
        $mine = Module::factory()->create(['name' => 'Steunkousen']);
        Module::factory()->create(['name' => 'Iemand anders zijn module']);

        ModuleActivation::factory()->ofModule($mine)->forOrganization($member->organization)->create();

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/modules')
            ->assertOk();

        $response->assertJsonPath('totalCount', 1);
        $response->assertJsonPath('items.0.name', 'Steunkousen');
    }

    #[Test]
    public function a_platform_administrator_sees_every_module(): void
    {
        $operator = $this->platformAdministrator();
        Module::factory()->count(3)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson('/api/modules')
            ->assertOk()
            ->assertJsonPath('totalCount', 3);
    }

    #[Test]
    public function the_listing_can_be_searched_by_name_regardless_of_case(): void
    {
        $operator = $this->platformAdministrator();
        Module::factory()->create(['name' => 'Subcutaan Injecteren']);
        Module::factory()->create(['name' => 'Oogdruppels Toedienen']);

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson('/api/modules?search=SUBCUTAAN')
            ->assertOk()
            ->assertJsonPath('totalCount', 1)
            ->assertJsonPath('items.0.name', 'Subcutaan Injecteren');
    }

    #[Test]
    public function a_module_nobody_switched_on_for_me_is_not_found_rather_than_forbidden(): void
    {
        // 404 on purpose: a 403 on a module that exists would let one organization enumerate what
        // the platform has written for the others.
        $member = User::factory()->create();
        $module = Module::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_module_shows_the_platforms_material_and_my_own_organizations(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()
            ->ofModule($module)
            ->forOrganization($member->organization)
            ->create();

        ModuleVideo::factory()->ofModule($module)->create(['title' => 'Van het platform']);
        ModuleVideo::factory()->ofActivation($activation)->create(['title' => 'Van ons']);
        ModuleLink::factory()->ofModule($module)->create(['title' => 'Platformlink']);
        ModuleContact::factory()->ofActivation($activation)->create(['name' => 'Joris van Houten']);

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk();

        $response->assertJsonPath('videos.0.title', 'Van het platform');
        $response->assertJsonPath('videos.0.isOrganizationSpecific', false);
        $response->assertJsonPath('videos.1.title', 'Van ons');
        $response->assertJsonPath('videos.1.isOrganizationSpecific', true);
        $response->assertJsonPath('links.0.title', 'Platformlink');
        $response->assertJsonPath('contacts.0.name', 'Joris van Houten');
    }

    #[Test]
    public function one_organizations_additions_never_appear_under_anothers_reading(): void
    {
        // The whole reason activations are rows of their own. Two organizations, one module, and
        // the phone number of one must not reach the people of the other.
        $module = Module::factory()->create();

        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $myActivation = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($mine->organization)->create();
        $theirActivation = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($theirs->organization)->create();

        ModuleContact::factory()->ofActivation($myActivation)->create(['name' => 'Onze contactpersoon']);
        ModuleContact::factory()->ofActivation($theirActivation)->create(['name' => 'Hun contactpersoon']);
        ModuleVideo::factory()->ofActivation($theirActivation)->create(['title' => 'Hun video']);

        // A platform link both organizations see, and one of each organization's own beside it, so
        // that "sees only their own" is distinguishable from "sees nothing of anybody's".
        ModuleLink::factory()->ofModule($module)->create(['title' => 'Landelijke richtlijn']);
        ModuleLink::factory()->ofActivation($myActivation)->create(['title' => 'Ons protocol']);
        ModuleLink::factory()->ofActivation($theirActivation)->create(['title' => 'Hun protocol']);

        $response = $this->withHeaders($this->tokenHeaders($mine))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk();

        $response->assertJsonCount(1, 'contacts');
        $response->assertJsonPath('contacts.0.name', 'Onze contactpersoon');
        $response->assertJsonMissing(['title' => 'Hun video']);

        // The links, which this test covered for contacts and videos but not for the third list.
        $response->assertJsonCount(2, 'links');
        $response->assertJsonPath('links.0.title', 'Landelijke richtlijn');
        $response->assertJsonPath('links.0.isOrganizationSpecific', false);
        $response->assertJsonPath('links.1.title', 'Ons protocol');
        $response->assertJsonPath('links.1.isOrganizationSpecific', true);
        $response->assertJsonMissing(['title' => 'Hun protocol']);
    }

    #[Test]
    public function a_platform_administrator_reading_a_module_sees_no_organizations_contacts(): void
    {
        // They are not reading it *at* an organization, so there is nothing of anybody's to show —
        // and picking one would be picking whose phone number to hand them.
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()->ofModule($module)->create();
        ModuleContact::factory()->ofActivation($activation)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->assertJsonCount(0, 'contacts');
    }

    #[Test]
    public function a_platform_administrator_is_not_shown_the_platform_organizations_own_copy(): void
    {
        // A platform administrator belongs to the platform organization, which can be given a
        // module like any other. Looking up their own organization's copy first handed them that
        // copy's contacts and links as though they were the module's.
        $operator = $this->platformAdministrator();
        $module = Module::factory()->create();
        $platformsCopy = ModuleActivation::factory()->ofModule($module)->forOrganization($operator->organization)->create();
        ModuleContact::factory()->ofActivation($platformsCopy)->create();
        ModuleLink::factory()->ofActivation($platformsCopy)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->assertJsonCount(0, 'contacts')
            ->assertJsonCount(0, 'links');
    }

    #[Test]
    public function a_module_lists_the_courses_attached_to_it(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $course = ELearning::factory()->create(['name' => 'Medicijnen prikken']);
        $module->eLearnings()->attach($course);

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->assertJsonPath('eLearnings.0.name', 'Medicijnen prikken');
    }

    #[Test]
    public function a_module_without_a_picture_says_so_instead_of_offering_an_address(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->withoutImage()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->assertJsonPath('imageUrl', null);

        // And the address answers honestly if a client asks anyway.
        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/image")
            ->assertNotFound();
    }

    #[Test]
    public function a_modules_picture_is_served_with_the_media_type_its_bytes_turned_out_to_be(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        Storage::put((string) $module->image_storage_key, self::PNG);

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function a_picture_whose_file_is_gone_is_a_missing_file_rather_than_a_broken_page(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        // Nothing written to the disk: the row names a file that is not there, which is reachable
        // rather than theoretical.
        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/image")
            ->assertNotFound();
    }

    #[Test]
    public function an_uploaded_video_is_served_under_the_module_it_belongs_to(): void
    {
        // A redirect to a read-only link on the video disk rather than the bytes: a player reads
        // a video a range at a time, which Azure answers and a PHP process holding it could not.
        $member = User::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $video = ModuleVideo::factory()->ofModule($module)->uploaded()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/videos/{$video->getKey()}/file")
            ->assertRedirect("https://videos.test/{$video->file_storage_key}?sig=read")
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function an_organizations_own_uploaded_video_is_addressed_under_the_shared_module(): void
    {
        // It hangs off the activation and carries no module of its own, so the address has to be
        // built from the module being read rather than derived from the row — which is also what
        // keeps this from being a query per video.
        $member = User::factory()->create();
        $module = Module::factory()->create();
        $activation = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($member->organization)->create();

        $video = ModuleVideo::factory()->ofActivation($activation)->uploaded()->create();

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk();

        $response->assertJsonPath(
            'videos.0.fileUrl',
            "/api/modules/{$module->getKey()}/videos/{$video->getKey()}/file",
        );

        $this->withHeaders($this->tokenHeaders($member))
            ->get((string) $response->json('videos.0.fileUrl'))
            ->assertRedirect("https://videos.test/{$video->file_storage_key}?sig=read");
    }

    #[Test]
    public function a_video_belonging_to_another_module_is_not_reachable_through_mine(): void
    {
        // The module in the address is where permission comes from, so the video has to be one of
        // that module's — otherwise one readable module would be a key to every upload.
        $member = User::factory()->create();
        $mine = Module::factory()->create();
        ModuleActivation::factory()->ofModule($mine)->forOrganization($member->organization)->create();

        $elsewhere = ModuleVideo::factory()->ofModule(Module::factory()->create())->uploaded()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$mine->getKey()}/videos/{$elsewhere->getKey()}/file")
            ->assertNotFound();
    }

    #[Test]
    public function another_organizations_own_video_is_not_reachable_through_the_shared_module(): void
    {
        $module = Module::factory()->create();

        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        ModuleActivation::factory()->ofModule($module)->forOrganization($mine->organization)->create();
        $theirActivation = ModuleActivation::factory()
            ->ofModule($module)->forOrganization($theirs->organization)->create();

        $theirVideo = ModuleVideo::factory()->ofActivation($theirActivation)->uploaded()->create();

        $this->withHeaders($this->tokenHeaders($mine))
            ->get("/api/modules/{$module->getKey()}/videos/{$theirVideo->getKey()}/file")
            ->assertNotFound();
    }

    #[Test]
    public function a_linked_video_has_no_file_to_serve(): void
    {
        $member = User::factory()->create();
        $module = Module::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $video = ModuleVideo::factory()->ofModule($module)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/videos/{$video->getKey()}/file")
            ->assertNotFound();
    }

    #[Test]
    public function every_content_endpoint_needs_a_token(): void
    {
        $module = Module::factory()->create();

        $this->getJson('/api/modules')->assertUnauthorized();
        $this->getJson("/api/modules/{$module->getKey()}")->assertUnauthorized();
        $this->get("/api/modules/{$module->getKey()}/image")->assertUnauthorized();
    }

    #[Test]
    public function the_minted_key_is_what_the_file_is_read_back_by(): void
    {
        // Guards the one thing a rename of `StoredFile::mintKey` would silently break: the row and
        // the disk have to agree about where the bytes went.
        $module = Module::factory()->create();
        $key = StoredFile::mintKey(Module::IMAGE_PREFIX, (string) $module->getKey(), 'image', 'png');

        $module->applyImage(new StoredFile($key, 'image/png', strlen(self::PNG)));
        $module->save();

        Storage::put($key, self::PNG);

        $member = User::factory()->create();
        ModuleActivation::factory()->ofModule($module)->forOrganization($member->organization)->create();

        $response = $this->withHeaders($this->tokenHeaders($member))
            ->get("/api/modules/{$module->getKey()}/image")
            ->assertOk();

        $this->assertSame(self::PNG, $response->getContent());
    }

    private function platformAdministrator(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->platform()->create()->getKey(),
            'role' => UserRole::PlatformAdministrator,
        ]);
    }
}
