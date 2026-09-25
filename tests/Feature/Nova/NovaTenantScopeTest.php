<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Enums\LoginTokenPurpose;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What an organization administrator sees in the panel.
 *
 * The panel was platform administrators only until they were let in, so every listing in it was
 * written when there was nobody to hide anything from. These are the tests for the other half of
 * that change: not that an organization administrator can get in, but that getting in shows them
 * their own tenant and no one else's. An unscoped listing here is a tenant leak rather than an
 * untidy page, which is why each one is asserted separately instead of trusting a shared helper.
 */
final class NovaTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_organization_administrator_can_sign_in_to_the_panel(): void
    {
        $admin = $this->signedInAdministrator();

        $this->assertAuthenticatedAs($admin->fresh(), 'web');
    }

    #[Test]
    public function the_roster_is_their_own_organization_only(): void
    {
        $admin = $this->signedInAdministrator();
        $colleague = User::factory()->for($admin->organization)->create();
        $stranger = User::factory()->for(Organization::factory())->create();

        $listed = $this->listedIdentifiers('/nova-api/users');

        $this->assertContains((string) $admin->getKey(), $listed);
        $this->assertContains((string) $colleague->getKey(), $listed);
        $this->assertNotContains((string) $stranger->getKey(), $listed);
    }

    #[Test]
    public function the_organizations_list_is_their_own_only(): void
    {
        $admin = $this->signedInAdministrator();
        $other = Organization::factory()->create();

        $listed = $this->listedIdentifiers('/nova-api/organizations');

        $this->assertSame([(string) $admin->organization_id], $listed);
        $this->assertNotContains((string) $other->getKey(), $listed);
    }

    /**
     * A detail page is reached by typing a key as readily as by clicking a row, so a boundary
     * stated only on the index is not stated at all.
     */
    #[Test]
    public function somebody_else_s_tenant_cannot_be_opened_by_its_key(): void
    {
        $this->signedInAdministrator();
        $stranger = User::factory()->for(Organization::factory())->create();

        $this->getJson('/nova-api/users/'.$stranger->getKey())
            ->assertStatus(Response::HTTP_NOT_FOUND);

        $this->getJson('/nova-api/organizations/'.$stranger->organization_id)
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * Creating, archiving and deleting a tenant belong to the platform. The use cases refuse an
     * organization administrator anyway; not offering the buttons is what stops the panel promising
     * something it will then answer with a banner.
     */
    #[Test]
    public function the_platforms_own_operations_are_not_offered(): void
    {
        $admin = $this->signedInAdministrator();

        $offered = $this->offeredActions('organizations', (string) $admin->organization_id);

        $this->assertNotContains('organisatie-aanmaken', $offered);
        $this->assertNotContains('organisatie-archiveren', $offered);
        $this->assertNotContains('organisatie-verwijderen', $offered);

        // What the API lets an administrator do for their own organization stays.
        $this->assertContains('organisatie-wijzigen', $offered);
        $this->assertContains('logo-uploaden', $offered);
    }

    /** The picker an invite opens names only the organizations the operator may invite into. */
    #[Test]
    public function the_organization_picker_names_no_other_tenant(): void
    {
        $admin = $this->signedInAdministrator();
        $other = Organization::factory()->create();

        $options = \App\Nova\Organization::options();

        $this->assertSame([(string) $admin->organization_id], array_keys($options));
        $this->assertArrayNotHasKey((string) $other->getKey(), $options);
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

    /** @return list<string> */
    private function offeredActions(string $resource, string $resourceId): array
    {
        $actions = $this->getJson("/nova-api/{$resource}/actions?resourceId={$resourceId}")
            ->assertOk()
            ->json('actions');

        if (! is_array($actions)) {
            self::fail('Nova listed no actions at all.');
        }

        $keys = [];

        foreach ($actions as $action) {
            if (is_array($action) && is_string($action['uriKey'] ?? null)) {
                $keys[] = $action['uriKey'];
            }
        }

        return $keys;
    }

    /** Signs an organization administrator in the way the panel does, through a real link. */
    private function signedInAdministrator(): User
    {
        config(['session.driver' => 'database']);

        $admin = User::factory()->administrator()
            ->for(Organization::factory())
            ->create();

        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $admin->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        $this->get(route('nova.sign-in.claim', ['token' => $secret->value]))
            ->assertRedirect(config('nova.path'));

        return $admin->refresh();
    }
}
