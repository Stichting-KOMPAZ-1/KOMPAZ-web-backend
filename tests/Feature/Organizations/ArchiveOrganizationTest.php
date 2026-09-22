<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\LoginTokenPurpose;
use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\SecretTokenFactory;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArchiveOrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_platform_administrator_archives_an_organization(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk()
            ->assertJsonPath('isArchived', true);

        $this->assertNotNull($organization->refresh()->archived_at);
    }

    /**
     * The point of archiving rather than deleting: nothing is destroyed, so the people and their
     * history are still here to come back to.
     */
    #[Test]
    public function archiving_destroys_nothing(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->create();
        $member = User::factory()->for($organization)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk();

        $this->assertDatabaseHas('organizations', ['id' => $organization->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $member->getKey(), 'deleted_at' => null]);
    }

    #[Test]
    public function archiving_signs_every_member_out_at_once(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->create();
        $member = User::factory()->for($organization)->create();
        $headers = $this->tokenHeaders($member);

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk();

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_sign_in_link_is_refused_while_the_organization_is_archived(): void
    {
        $organization = Organization::factory()->create();
        $member = User::factory()->for($organization)->create();
        $token = $this->issueLinkFor($member);

        $organization->archived_at = Carbon::now();
        $organization->save();

        $this->postJson('/api/auth/tokens', ['token' => $token])
            ->assertUnauthorized()
            ->assertJsonPath('detail', OrganizationMessages::ORGANIZATION_ARCHIVED);
    }

    #[Test]
    public function asking_for_a_link_in_an_archived_organization_sends_nothing(): void
    {
        Mail::fake();
        $member = User::factory()->for(Organization::factory()->archived())->create();

        $this->postJson('/api/auth/magic-link', ['email' => $member->email])
            ->assertNoContent();

        Mail::assertNotSent(MagicLinkMail::class);
    }

    #[Test]
    public function the_platform_organization_cannot_be_archived(): void
    {
        $operator = $this->platformAdministrator();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$operator->organization_id}/archive")
            ->assertConflict()
            ->assertJsonPath('detail', OrganizationMessages::PLATFORM_CANNOT_BE_ARCHIVED);
    }

    #[Test]
    public function archiving_one_that_is_already_archived_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->archived()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertConflict()
            ->assertJsonPath('detail', OrganizationMessages::ALREADY_ARCHIVED);
    }

    #[Test]
    public function an_organization_that_is_not_archived_cannot_be_restored(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertConflict()
            ->assertJsonPath('detail', OrganizationMessages::NOT_ARCHIVED);
    }

    #[Test]
    public function restoring_lets_its_members_sign_in_again(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->archived()->create();
        $member = User::factory()->for($organization)->create();

        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk()
            ->assertJsonPath('isArchived', false);

        $this->postJson('/api/auth/tokens', ['token' => $this->issueLinkFor($member)])
            ->assertOk();
    }

    /**
     * A link issued before the organization closed is not handed back by reopening it. It was a
     * credential, and archiving withdrew it on purpose.
     */
    #[Test]
    public function archiving_withdraws_the_links_that_were_outstanding(): void
    {
        $operator = $this->platformAdministrator();
        $organization = Organization::factory()->create();
        $member = User::factory()->for($organization)->create();
        $token = $this->issueLinkFor($member);

        $this->withHeaders($this->tokenHeaders($operator))
            ->postJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk();

        $this->assertSame(0, LoginToken::query()->where('user_id', $member->getKey())->count());

        $this->withHeaders($this->tokenHeaders($operator))
            ->deleteJson("/api/organizations/{$organization->getKey()}/archive")
            ->assertOk();

        $this->postJson('/api/auth/tokens', ['token' => $token])->assertUnauthorized();
    }

    #[Test]
    public function the_list_leaves_archived_organizations_out_unless_asked(): void
    {
        $operator = $this->platformAdministrator();
        Organization::factory()->create();
        $closed = Organization::factory()->archived()->create();

        // The operator's own platform organization and the open one; the archived one is absent.
        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('totalCount', 2)
            ->assertJsonMissing(['id' => $closed->getKey()]);

        $this->withHeaders($this->tokenHeaders($operator))
            ->getJson('/api/organizations?includeArchived=1')
            ->assertOk()
            ->assertJsonPath('totalCount', 3);
    }

    #[Test]
    public function an_organization_administrator_cannot_archive_anything(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/organizations/{$admin->organization_id}/archive")
            ->assertForbidden();
    }

    private function issueLinkFor(User $user): string
    {
        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        return $secret->value;
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
    }
}
