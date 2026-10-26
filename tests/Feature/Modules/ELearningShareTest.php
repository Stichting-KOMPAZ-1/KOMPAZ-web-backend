<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A course as each organization shares it, for the platform's share dialog (KOM-94): the
 * organizations to pick from, and the link and QR code of the one picked.
 */
final class ELearningShareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_platform_is_offered_only_the_organizations_that_share_the_course(): void
    {
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        $course = ELearning::factory()->create(['name' => 'Steunkousen']);
        $module = Module::factory()->create(['name' => 'Wondzorg']);
        $module->eLearnings()->attach($course);

        $zuid = $this->activate($module, Organization::factory()->create(['name' => 'Regio Zuid']));
        $midden = $this->activate($module, Organization::factory()->create(['name' => 'Regio Midden']));

        // Given the module but closed, and given only a module that does not show the course.
        $this->activate($module, Organization::factory()->archived()->create());
        $this->activate(Module::factory()->create(), Organization::factory()->create());

        $items = $this->withHeaders($this->tokenHeaders($this->operator()))
            ->getJson("/api/e-learnings/{$course->getKey()}/shares")
            ->assertOk()
            ->json('items');

        $this->assertSame([
            [
                'organizationId' => $midden->getKey(),
                'organizationName' => 'Regio Midden',
                'moduleId' => $module->getKey(),
                'moduleName' => 'Wondzorg',
                'shareUrl' => 'https://kompaz.test/delen/regio-midden/wondzorg/steunkousen',
                'shareSlugs' => ['organization' => 'regio-midden', 'module' => 'wondzorg', 'eLearning' => 'steunkousen'],
                'qrShareUrl' => 'https://kompaz.test/delen/regio-midden/wondzorg/steunkousen?utm_source=qr&utm_medium=qr',
                'qrCodeUrl' => "/api/e-learnings/{$course->getKey()}/shares/{$midden->getKey()}/{$module->getKey()}/qr-code",
            ],
            [
                'organizationId' => $zuid->getKey(),
                'organizationName' => 'Regio Zuid',
                'moduleId' => $module->getKey(),
                'moduleName' => 'Wondzorg',
                'shareUrl' => 'https://kompaz.test/delen/regio-zuid/wondzorg/steunkousen',
                'shareSlugs' => ['organization' => 'regio-zuid', 'module' => 'wondzorg', 'eLearning' => 'steunkousen'],
                'qrShareUrl' => 'https://kompaz.test/delen/regio-zuid/wondzorg/steunkousen?utm_source=qr&utm_medium=qr',
                'qrCodeUrl' => "/api/e-learnings/{$course->getKey()}/shares/{$zuid->getKey()}/{$module->getKey()}/qr-code",
            ],
        ], $items);
    }

    #[Test]
    public function a_course_shared_nowhere_answers_an_empty_list(): void
    {
        $course = ELearning::factory()->create();

        $this->withHeaders($this->tokenHeaders($this->operator()))
            ->getJson("/api/e-learnings/{$course->getKey()}/shares")
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function one_organization_gets_one_share_for_each_module_that_shows_the_course(): void
    {
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        $course = ELearning::factory()->create(['name' => 'Steunkousen']);
        $organization = Organization::factory()->create(['name' => 'Regio Midden']);

        foreach (['Diabetes', 'Wondzorg'] as $name) {
            $module = Module::factory()->create(['name' => $name]);
            $module->eLearnings()->attach($course);
            $this->activate($module, $organization);
        }

        $items = $this->withHeaders($this->tokenHeaders($this->operator()))
            ->getJson("/api/e-learnings/{$course->getKey()}/shares")
            ->assertOk()
            ->json('items');

        $this->assertSame(['Diabetes', 'Wondzorg'], array_column($items, 'moduleName'));
        $this->assertSame([
            'https://kompaz.test/delen/regio-midden/diabetes/steunkousen',
            'https://kompaz.test/delen/regio-midden/wondzorg/steunkousen',
        ], array_column($items, 'shareUrl'));
    }

    #[Test]
    public function the_platform_reads_one_organizations_share_and_its_qr_code(): void
    {
        config(['kompaz.frontend_url' => 'https://kompaz.test']);
        [$organization, $course, $module] = $this->shared('Regio Midden', 'Steunkousen');
        $headers = $this->tokenHeaders($this->operator());
        $address = "/api/e-learnings/{$course->getKey()}/shares/{$organization->getKey()}/{$module->getKey()}";

        $this->withHeaders($headers)
            ->getJson($address)
            ->assertOk()
            ->assertExactJson([
                'organizationId' => $organization->getKey(),
                'organizationName' => 'Regio Midden',
                'moduleId' => $module->getKey(),
                'moduleName' => 'Wondzorg',
                'shareUrl' => 'https://kompaz.test/delen/regio-midden/wondzorg/steunkousen',
                'shareSlugs' => ['organization' => 'regio-midden', 'module' => 'wondzorg', 'eLearning' => 'steunkousen'],
                'qrShareUrl' => 'https://kompaz.test/delen/regio-midden/wondzorg/steunkousen?utm_source=qr&utm_medium=qr',
                'qrCodeUrl' => $address.'/qr-code',
            ]);

        $png = $this->withHeaders($headers)
            ->get($address.'/qr-code')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="qr-regio-midden-wondzorg-steunkousen.png"');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $png->getContent());

        $this->withHeaders($headers)
            ->get($address.'/qr-code?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-regio-midden-wondzorg-steunkousen.png"');
    }

    #[Test]
    public function no_link_is_handed_out_for_an_organization_that_does_not_share_the_course(): void
    {
        // Its address would lead to a 404, so asking for it is one too.
        [, $course, $module] = $this->shared();
        $headers = $this->tokenHeaders($this->operator());

        $elsewhere = Organization::factory()->create();
        $archived = $this->activate($module, Organization::factory()->archived()->create());

        foreach ([$elsewhere, $archived] as $organization) {
            $address = "/api/e-learnings/{$course->getKey()}/shares/{$organization->getKey()}/{$module->getKey()}";

            $this->withHeaders($headers)->getJson($address)->assertNotFound();
            $this->withHeaders($headers)->getJson($address.'/qr-code')->assertNotFound();
        }
    }

    #[Test]
    public function sharing_a_course_is_the_platforms_alone(): void
    {
        [$organization, $course, $module] = $this->shared();
        $administrator = User::factory()->administrator()->for($organization)->create();
        $address = "/api/e-learnings/{$course->getKey()}/shares";

        // Even their own organization's: they are handed that on the module page instead.
        foreach ([$address, "{$address}/{$organization->getKey()}/{$module->getKey()}", "{$address}/{$organization->getKey()}/{$module->getKey()}/qr-code"] as $path) {
            $this->withHeaders($this->tokenHeaders($administrator))->getJson($path)->assertForbidden();
        }
    }

    private function operator(): User
    {
        return User::factory()->platformAdministrator()->for(Organization::factory()->platform())->create();
    }

    private function activate(Module $module, Organization $organization): Organization
    {
        ModuleActivation::factory()->ofModule($module)->forOrganization($organization)->create();

        return $organization;
    }

    /** @return array{Organization, ELearning, Module} */
    private function shared(?string $organizationName = null, ?string $courseName = null): array
    {
        $course = ELearning::factory()->create(array_filter(['name' => $courseName]));
        $module = Module::factory()->create(['name' => 'Wondzorg']);
        $module->eLearnings()->attach($course);
        $organization = $this->activate($module, Organization::factory()->create(array_filter(['name' => $organizationName])));

        return [$organization, $course, $module];
    }
}
