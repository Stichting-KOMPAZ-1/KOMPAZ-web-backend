<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Enums\ContentBlockType;
use App\Models\CarouselSlide;
use App\Models\Chapter;
use App\Models\ContentBlock;
use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\Organization;
use App\Models\OrganizationLogo;
use App\Models\Step;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Images\PlaceholderLogo;
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
    public function an_organization_a_module_and_a_course_are_given_slugs_that_survive_a_rename(): void
    {
        // Printed in QR codes, so a rename must not move the address.
        $organization = Organization::factory()->create(['name' => 'Maastricht UMC+']);
        $module = Module::factory()->create(['name' => 'Wondzorg']);
        $course = ELearning::factory()->create(['name' => 'Medicijnen prikken onder de huid']);

        $this->assertSame('maastricht-umc', $organization->slug);
        $this->assertSame('wondzorg', $module->slug);
        $this->assertSame('medicijnen-prikken-onder-de-huid', $course->slug);

        $organization->applyName('Maastricht Universitair Medisch Centrum');
        $organization->save();
        $module->update(['name' => 'Huid en wonden']);
        $course->update(['name' => 'Subcutaan injecteren']);

        $this->assertSame('maastricht-umc', $organization->fresh()?->slug);
        $this->assertSame('wondzorg', $module->fresh()?->slug);
        $this->assertSame('medicijnen-prikken-onder-de-huid', $course->fresh()?->slug);

        // A second course of the same name gets an address of its own.
        $this->assertSame(
            'medicijnen-prikken-onder-de-huid-2',
            ELearning::factory()->create(['name' => 'Medicijnen prikken onder de huid'])->slug,
        );

        $this->assertSame('wondzorg-2', Module::factory()->create(['name' => 'Wondzorg'])->slug);
        $this->assertSame('qr-2', Module::factory()->create(['name' => 'QR'])->slug);
    }

    #[Test]
    public function the_links_are_on_the_frontend_and_the_qr_code_is_the_link_marked_as_a_scan(): void
    {
        // One address for one page (KOM-110): a scan is told apart by its campaign parameters,
        // so it is not a second page that counts every scan twice.
        config(['kompaz.frontend_url' => 'https://kompaz.test/']);

        $organization = Organization::factory()->create(['name' => 'Regio Midden']);
        $module = Module::factory()->create(['name' => 'Wondzorg']);
        $course = ELearning::factory()->create(['name' => 'Steunkousen']);

        $this->assertSame('https://kompaz.test/delen/regio-midden/wondzorg/steunkousen', ShareLink::direct($organization, $module, $course));
        $this->assertSame('https://kompaz.test/delen/regio-midden/wondzorg/steunkousen?utm_source=qr&utm_medium=qr', ShareLink::qr($organization, $module, $course));
    }

    #[Test]
    public function a_shared_course_is_read_with_no_account_and_every_address_in_it_stays_shared(): void
    {
        [$organization, $course, $module] = $this->shared();
        $chapter = Chapter::factory()->of($course)->create();
        $part = Step::factory()->of($chapter)->create(['name' => 'Wat ga je leren?']);
        $block = ContentBlock::factory()->of($part)->image()->create();
        $carousel = ContentBlock::factory()->of($part, 1)->create([
            'type' => ContentBlockType::Carousel,
            'title' => null,
            'body' => null,
        ]);
        $slide = CarouselSlide::factory()->for($carousel, 'block')->create();

        Storage::put($course->image_storage_key, self::PNG);
        Storage::put((string) $block->file_storage_key, self::PNG);
        Storage::put($slide->file_storage_key, self::PNG);

        $base = "/api/shared/{$organization->slug}/{$module->slug}/{$course->slug}";

        $this->getJson($base)
            ->assertOk()
            ->assertJsonPath('name', $course->name)
            ->assertJsonPath('imageUrl', $base.'/image')
            ->assertJsonPath('chapters.0.parts.0.name', 'Wat ga je leren?');

        $this->get($base.'/image')->assertOk()->assertHeader('Content-Type', 'image/png');

        $partResponse = $this->getJson($base.'/parts/'.$part->getKey())->assertOk();
        $fileUrl = $partResponse->json('blocks.0.fileUrl');

        // Not the signed-in address, which would ask a visitor to sign in.
        $this->assertSame($base.'/parts/'.$part->getKey().'/blocks/'.$block->getKey().'/file', $fileUrl);

        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

        $slideUrl = (string) $partResponse->json('blocks.1.slides.0.imageUrl');
        $this->assertSame($base.'/parts/'.$part->getKey().'/blocks/'.$carousel->getKey().'/slides/'.$slide->getKey().'/image', $slideUrl);
        $this->get($slideUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function a_shared_course_names_the_organization_that_shared_it_for_the_page_header(): void
    {
        // KOM-110: the public page has no signed-in organization to draw its header from.
        [$organization, $course, $module] = $this->shared('Regio Midden');
        $base = "/api/shared/{$organization->slug}/{$module->slug}/{$course->slug}";

        $this->getJson($base)
            ->assertOk()
            // Its name and logo, and nothing else about it.
            ->assertExactJsonStructure([
                'id', 'name', 'imageUrl', 'sharedBy' => ['name', 'hasLogo', 'logoUrl'], 'contacts',
                'chapters', 'numberedChapters', 'summaryChapters', 'createdUtc', 'updatedUtc',
            ])
            ->assertJsonPath('sharedBy', [
                'name' => 'Regio Midden',
                'hasLogo' => false,
                'logoUrl' => $base.'/organization/logo',
            ]);

        // With no logo of its own, the address answers with the placeholder — and asks for no token.
        $this->get($base.'/organization/logo')
            ->assertOk()
            ->assertHeader('Content-Type', PlaceholderLogo::contentType());

        $key = OrganizationLogo::mintKey((string) $organization->getKey(), 'png');
        Storage::put($key, self::PNG);
        OrganizationLogo::query()->create([
            'organization_id' => $organization->getKey(),
            'storage_key' => $key,
            'content_type' => 'image/png',
            'byte_count' => strlen(self::PNG),
        ]);

        $this->getJson($base)->assertOk()->assertJsonPath('sharedBy.hasLogo', true);

        $logo = $this->get($base.'/organization/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(self::PNG, $logo->getContent());
    }

    #[Test]
    public function an_organizations_logo_is_not_shared_through_a_course_it_does_not_share(): void
    {
        [, $course] = $this->shared();
        $other = Organization::factory()->create();

        $this->getJson("/api/shared/{$other->slug}/{$course->slug}/organization/logo")->assertNotFound();
    }

    #[Test]
    public function a_signed_in_reader_is_not_told_who_shared_the_course(): void
    {
        [$organization, $course] = $this->shared();
        $member = User::factory()->for($organization)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertOk()
            ->assertJsonPath('sharedBy', null);
        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/e-learnings/{$course->getKey()}")
            ->assertJsonPath('contacts', []);
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
    public function a_module_link_requires_that_exact_module_and_returns_only_its_contacts(): void
    {
        [$organization, $course, $module] = $this->shared('Regio Midden', 'Steunkousen');
        $activation = $module->activationFor((string) $organization->getKey());
        $this->assertNotNull($activation);
        ModuleContact::factory()->ofActivation($activation)->create(['name' => 'Wondteam', 'email' => 'wond@example.nl']);

        $other = Module::factory()->create(['name' => 'Diabetes']);
        $other->eLearnings()->attach($course);
        $otherActivation = ModuleActivation::factory()->ofModule($other)->forOrganization($organization)->create();
        ModuleContact::factory()->ofActivation($otherActivation)->create(['name' => 'Diabetesteam', 'email' => 'diabetes@example.nl']);

        $inactive = Module::factory()->create(['name' => 'Niet actief']);
        $inactive->eLearnings()->attach($course);
        $notShowing = Module::factory()->create(['name' => 'Zonder cursus']);
        ModuleActivation::factory()->ofModule($notShowing)->forOrganization($organization)->create();
        $archived = Organization::factory()->archived()->create(['name' => 'Gesloten regio']);
        ModuleActivation::factory()->ofModule($module)->forOrganization($archived)->create();

        $base = "/api/shared/{$organization->slug}/{$module->slug}/{$course->slug}";
        $this->getJson($base)
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.name', 'Wondteam');

        foreach ([
            "/api/shared/{$organization->slug}/onbekend/{$course->slug}",
            "/api/shared/{$organization->slug}/{$module->slug}/onbekend",
            "/api/shared/onbekend/{$module->slug}/{$course->slug}",
            "/api/shared/{$organization->slug}/{$inactive->slug}/{$course->slug}",
            "/api/shared/{$organization->slug}/{$notShowing->slug}/{$course->slug}",
            "/api/shared/{$archived->slug}/{$module->slug}/{$course->slug}",
        ] as $address) {
            $this->getJson($address)
                ->assertNotFound()
                ->assertJsonPath('detail', 'Deze e-learning bestaat niet of is niet beschikbaar.');
        }
    }

    #[Test]
    public function a_legacy_link_merges_module_contacts_in_module_order_and_deduplicates_email(): void
    {
        [$organization, $course, $wondzorg] = $this->shared('Regio Midden', 'Steunkousen');
        $wondzorg->forceFill(['name' => 'Wondzorg'])->save();
        $wondActivation = $wondzorg->activationFor((string) $organization->getKey());
        $this->assertNotNull($wondActivation);
        ModuleContact::factory()->ofActivation($wondActivation)->create(['name' => 'Dubbel tweede', 'email' => 'TEAM@example.nl', 'position' => 0]);
        ModuleContact::factory()->ofActivation($wondActivation)->create(['name' => 'Wondteam', 'email' => 'wond@example.nl', 'position' => 1]);

        $diabetes = Module::factory()->create(['name' => 'Diabetes']);
        $diabetes->eLearnings()->attach($course);
        $diabetesActivation = ModuleActivation::factory()->ofModule($diabetes)->forOrganization($organization)->create();
        ModuleContact::factory()->ofActivation($diabetesActivation)->create(['name' => 'Diabetesteam', 'email' => 'team@EXAMPLE.nl']);

        $this->getJson("/api/shared/{$organization->slug}/{$course->slug}")
            ->assertOk()
            ->assertJsonCount(2, 'contacts')
            ->assertJsonPath('contacts.0.name', 'Diabetesteam')
            ->assertJsonPath('contacts.1.name', 'Wondteam');
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
        $this->assertSame('https://kompaz.test/delen/regio-midden/wondzorg/steunkousen', $item['shareUrl']);
        $this->assertSame('https://kompaz.test/delen/regio-midden/wondzorg/steunkousen?utm_source=qr&utm_medium=qr', $item['qrShareUrl']);
        $this->assertSame("/api/modules/{$module->getKey()}/e-learnings/{$course->getKey()}/qr-code", $item['qrCodeUrl']);

        $png = $this->withHeaders($this->tokenHeaders($member))
            ->get($item['qrCodeUrl'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="qr-regio-midden-wondzorg-steunkousen.png"');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $png->getContent());

        $this->withHeaders($this->tokenHeaders($member))
            ->get($item['qrCodeUrl'].'?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-regio-midden-wondzorg-steunkousen.png"');
    }

    #[Test]
    public function a_platform_administrator_is_handed_no_links_on_the_module_page(): void
    {
        // They read a module at no organization, so there is nobody whose link it would be. They
        // pick one through the course's shares instead (ELearningShareTest).
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
        $module = Module::factory()->create(['name' => 'Wondzorg']);

        $module->eLearnings()->attach($course);
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        return [$organization, $course, $module];
    }
}
