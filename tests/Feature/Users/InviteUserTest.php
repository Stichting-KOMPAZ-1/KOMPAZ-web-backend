<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InviteUserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_administrator_invites_somebody_into_their_own_organization(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();

        $response = $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::Member->value,
            ])
            ->assertCreated();

        $response->assertJsonPath('status', UserStatus::Invited->value);
        $response->assertJsonPath('organizationId', $admin->organization_id);
        Mail::assertSent(InvitationMail::class);
    }

    #[Test]
    public function a_member_cannot_invite(): void
    {
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::Member->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function an_administrator_cannot_appoint_another_administrator(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::Administrator->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('detail', 'Deze gebruiker kan alleen een lagere rol dan de eigen rol toekennen.');
    }

    #[Test]
    public function an_administrator_cannot_invite_into_another_organization(): void
    {
        $admin = User::factory()->administrator()->create();
        $elsewhere = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::Member->value,
                'organizationId' => $elsewhere->getKey(),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_platform_administrator_role_cannot_be_granted_in_a_tenant(): void
    {
        $platformOrganization = Organization::factory()->platform()->create();
        $platformAdmin = User::factory()->platformAdministrator()
            ->for($platformOrganization)
            ->create();

        $tenant = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::PlatformAdministrator->value,
                'organizationId' => $tenant->getKey(),
            ])
            ->assertConflict();
    }

    #[Test]
    public function re_inviting_a_pending_user_resends_rather_than_conflicting(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $pending = User::factory()->invited()
            ->for($admin->organization)
            ->create(['name' => 'Oude Naam']);

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => $pending->email,
                'name' => 'Nieuwe Naam',
                'role' => UserRole::Member->value,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Nieuwe Naam');

        $this->assertSame(1, User::query()->where('normalized_email', $pending->normalized_email)->count());
        Mail::assertSent(InvitationMail::class);
    }

    #[Test]
    public function inviting_an_active_user_is_a_conflict(): void
    {
        $admin = User::factory()->administrator()->create();
        $existing = User::factory()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => $existing->email,
                'name' => 'Iemand',
                'role' => UserRole::Member->value,
            ])
            ->assertConflict()
            ->assertJsonPath('detail', sprintf(
                'Er bestaat al een gebruiker met het e-mailadres "%s".',
                $existing->email,
            ));
    }

    #[Test]
    public function inviting_a_deleted_address_revives_that_row(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $deleted = User::factory()->deleted()
            ->for($admin->organization)
            ->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => $deleted->email,
                'name' => 'Terug Van Weggeweest',
                'role' => UserRole::Member->value,
            ])
            ->assertCreated()
            ->assertJsonPath('status', UserStatus::Invited->value)
            ->assertJsonPath('id', $deleted->getKey());

        $revived = User::query()->find($deleted->getKey());
        $this->assertNotNull($revived, 'Deleting somebody must not burn their email address.');
        $this->assertNull($revived->deleted_at);
    }

    #[Test]
    public function an_invitation_is_issued_as_a_single_use_secret(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/users/invitations', [
                'email' => 'nieuw@example.com',
                'name' => 'Nieuwe Collega',
                'role' => UserRole::Member->value,
            ])
            ->assertCreated();

        $token = LoginToken::query()->sole();
        $this->assertSame(64, strlen($token->token_hash), 'Only a SHA-256 hash is stored, never the secret.');
    }

    #[Test]
    public function resending_an_invitation_retires_the_previous_link(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $pending = User::factory()->invited()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/users/{$pending->getKey()}/invitations")
            ->assertAccepted();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/users/{$pending->getKey()}/invitations")
            ->assertAccepted();

        $this->assertSame(1, LoginToken::query()->whereNull('consumed_at')->count());
    }

    #[Test]
    public function an_accepted_invitation_cannot_be_resent(): void
    {
        $admin = User::factory()->administrator()->create();
        $active = User::factory()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/users/{$active->getKey()}/invitations")
            ->assertConflict()
            ->assertJsonPath('detail', 'De uitnodiging is al geaccepteerd.');
    }
}
