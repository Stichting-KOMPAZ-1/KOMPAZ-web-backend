<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Links\ShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A course as an organization shares it with people who have no account (KOM-95): the slugs its
 * address is made of, and the API the frontend reads it from.
 */
final class SharedELearningTest extends TestCase
{
    use RefreshDatabase;

    private const string PNG = "\x89PNG\r\n\x1a\n".'the rest does not matter';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    #[Test]
    public function an_organization_and_a_course_are_given_a_slug_that_survives_a_rename(): void
    {
        // Printed in QR codes, so a rename must not move the address.
        $organization = Organization::factory()->create(['name' => 'Maastricht UMC+']);
        $course = ELearning::factory()->create(['name' => 'Medicijnen prikken onder de huid']);

        $this->assertSame('maastricht-umc', $organization->slug);
        $this->assertSame('medicijnen-prikken-onder-de-huid', $course->slug);

        $organization->applyName('Maastricht Universitair Medisch Centrum');
        $organization->save();
        $course->update(['name' => 'Subcutaan injecteren']);

        $this->assertSame('maastricht-umc', $organization->fresh()?->slug);
        $this->assertSame('medicijnen-prikken-onder-de-huid', $course->fresh()?->slug);

        // A second course of the same name gets an address of its own.
        $this->assertSame(
            'medicijnen-prikken-onder-de-huid-2',
            ELearning::factory()->create(['name' => 'Medicijnen prikken onder de huid'])->slug,
        );
    }

    #[Test]
    public function the_links_are_on_the_frontend_and_the_qr_code_has_an_address_of_its_own(): void
    {
        config(['kompaz.frontend_url' => 'https://kompaz.test/']);

        $organization = Organization::factory()->create(['name' => 'Regio Midden']);
        $course = ELearning::factory()->create(['name' => 'Steunkousen']);

        $this->assertSame('https://kompaz.test/regio-midden/steunkousen', ShareLink::direct($organization, $course));
        $this->assertSame('https://kompaz.test/regio-midden/qr/steunkousen', ShareLink::qr($organization, $course));
    }

    #[Test]
    public function a_shared_course_is_read_with_no_account_and_every_address_in_it_stays_shared(): void
    {
        [$organization, $course] = $this->shared();
        $chapter = Chapter::factory()->of($course)->create();
        $part = Step::factory()->of($chapter)->create(['name' => 'Wat ga je leren?']);
        $block = ContentBlock::factory()->of($part)->image()->create();

        Storage::put($course->image_storage_key, self::PNG);
        Storage::put((string) $block->file_storage_key, self::PNG);

        $base = "/api/shared/{$organization->slug}/{$course->slug}";

        $this->getJson($base)
            ->assertOk()
            ->assertJsonPath('name', $course->name)
            ->assertJsonPath('imageUrl', $base.'/image')
            ->assertJsonPath('chapters.0.parts.0.name', 'Wat ga je leren?');

        $this->get($base.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');

        $fileUrl = $this->getJson($base.'/parts/'.$part->getKey())
            ->assertOk()
            ->json('blocks.0.fileUrl');

        // Not the signed-in address, which would ask a visitor to sign in.
        $this->assertSame($base.'/parts/'.$part->getKey().'/blocks/'.$block->getKey().'/file', $fileUrl);

        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function a_course_is_only_shared_by_an_organization_that_has_a_module_showing_it(): void
    {
        [$organization, $course] = $this->shared();
        $other = Organization::factory()->create();
        $unshared = ELearning::factory()->create();

        // Another organization's address for a course it was never given, a course nobody was
        // given, and addresses that do not exist: all the same 404, so nothing can be enumerated.
        foreach ([
            "/api/shared/{$other->slug}/{$course->slug}",
            "/api/shared/{$organization->slug}/{$unshared->slug}",
            "/api/shared/onbekend/{$course->slug}",
            "/api/shared/{$organization->slug}/onbekend",
        ] as $address) {
            $this->getJson($address)
                ->assertNotFound()
                ->assertJsonPath('detail', 'Deze e-learning bestaat niet of is niet beschikbaar.');
        }
    }

    #[Test]
    public function a_link_stops_working_when_the_course_is_no_longer_shared(): void
    {
        [$organization, $course, $module] = $this->shared();
        $address = "/api/shared/{$organization->slug}/{$course->slug}";

        $this->getJson($address)->assertOk();

        $module->eLearnings()->detach($course);
        $this->getJson($address)->assertNotFound();

        $module->eLearnings()->attach($course);
        $this->getJson($address)->assertOk();

        $organization->forceFill(['archived_at' => now()])->save();
        $this->getJson($address)->assertNotFound();
    }

    #[Test]
    public function a_part_of_another_course_is_not_reachable_through_a_shared_one(): void
    {
        [$organization, $course] = $this->shared();
        $elsewhere = Step::factory()->of(Chapter::factory()->create())->create();

        $this->getJson("/api/shared/{$organization->slug}/{$course->slug}/parts/{$elsewhere->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function the_organizations_a_course_is_shared_with_are_those_given_a_module_showing_it(): void
    {
        [$organization, $course] = $this->shared();
        Organization::factory()->create();
        $archived = Organization::factory()->archived()->create();
        ModuleActivation::factory()->ofModule($course->modules()->firstOrFail())->forOrganization($archived)->create();

        $this->assertSame(
            [(string) $organization->getKey()],
            ModuleAccess::organizationsSharing($course)->pluck('id')->all(),
        );
    }

    #[Test]
    public function a_care_professional_is_handed_their_organizations_links_on_the_module_page(): void
    {
        // KOM-81: what a care professional shares is their own organization's link.
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        [$organization, $course, $module] = $this->shared('Regio Midden', 'Steunkousen');
        $member = User::factory()->for($organization)->create();

        $item = $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->json('eLearnings.0');

        $this->assertIsArray($item);
        $this->assertSame('https://kompaz.test/regio-midden/steunkousen', $item['shareUrl']);
        $this->assertSame('https://kompaz.test/regio-midden/qr/steunkousen', $item['qrShareUrl']);
        $this->assertSame("/api/modules/{$module->getKey()}/e-learnings/{$course->getKey()}/qr-code", $item['qrCodeUrl']);

        $png = $this->withHeaders($this->tokenHeaders($member))
            ->get($item['qrCodeUrl'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="qr-regio-midden-steunkousen.png"');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $png->getContent());

        $this->withHeaders($this->tokenHeaders($member))
            ->get($item['qrCodeUrl'].'?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-regio-midden-steunkousen.png"');
    }

    #[Test]
    public function a_platform_administrator_shares_from_the_panel_and_is_handed_no_links_here(): void
    {
        // They read a module at no organization, so there is nobody whose link it would be.
        [, $course, $module] = $this->shared();
        $operator = User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/modules/{$module->getKey()}")
            ->assertOk()
            ->assertJsonPath('eLearnings.0.shareUrl', null)
            ->assertJsonPath('eLearnings.0.qrShareUrl', null)
            ->assertJsonPath('eLearnings.0.qrCodeUrl', null);

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson("/api/modules/{$module->getKey()}/e-learnings/{$course->getKey()}/qr-code")
            ->assertNotFound();
    }

    #[Test]
    public function a_qr_code_is_only_for_a_course_on_a_module_the_reader_has(): void
    {
        [$organization, , $module] = $this->shared();
        $member = User::factory()->for($organization)->create();
        $elsewhere = ELearning::factory()->create();
        [, $theirCourse, $theirModule] = $this->shared();

        // A course this module does not show, and a module this organization was not given.
        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$module->getKey()}/e-learnings/{$elsewhere->getKey()}/qr-code")
            ->assertNotFound();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/modules/{$theirModule->getKey()}/e-learnings/{$theirCourse->getKey()}/qr-code")
            ->assertNotFound();
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
}
