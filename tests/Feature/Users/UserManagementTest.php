<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Mail\AccountDeletedMail;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_roster_is_scoped_to_the_callers_own_organization(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->count(2)->for($admin->organization)->create();
        User::factory()->count(3)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('totalCount', 3);
    }

    #[Test]
    public function a_platform_administrator_sees_every_organization(): void
    {
        $platformAdmin = User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
        User::factory()->count(4)->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('totalCount', 5);
    }

    #[Test]
    public function deleted_users_are_left_out_unless_asked_for(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->deleted()->for($admin->organization)->create();

        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/users')
            ->assertOk()->assertJsonPath('totalCount', 1);

        $this->withHeaders($headers)->getJson('/api/users?includeDeleted=1')
            ->assertOk()->assertJsonPath('totalCount', 2);
    }

    #[Test]
    public function the_search_ignores_case_and_escapes_wildcards(): void
    {
        $admin = User::factory()->administrator()->create(['name' => 'Beheerder']);
        User::factory()->for($admin->organization)->create(['name' => 'Renée de Vries']);
        User::factory()->for($admin->organization)->create(['name' => '100% Koffie']);

        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/users?search=renée')
            ->assertOk()->assertJsonPath('totalCount', 1);

        // A typed wildcard is a literal, not "everything".
        $this->withHeaders($headers)->getJson('/api/users?search=%25')
            ->assertOk()->assertJsonPath('totalCount', 1);
    }

    #[Test]
    public function a_page_past_the_end_is_empty_rather_than_wrong(): void
    {
        $admin = User::factory()->administrator()->create();

        // A client picks both numbers, and the offset must not wrap.
        $this->withHeaders($this->tokenHeaders($admin))
            ->getJson('/api/users?pageNumber=2147483647&pageSize=100')
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('totalCount', 1);
    }

    #[Test]
    public function an_administrator_renames_somebody_in_their_organization(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => 'Nieuwe Naam',
                'email' => $member->email,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Nieuwe Naam');
    }

    #[Test]
    public function an_administrator_cannot_change_a_role(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => $member->email,
                'role' => UserRole::Administrator->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('detail', 'Alleen een platformbeheerder kan de rol van iemand wijzigen.');
    }

    #[Test]
    public function changing_an_address_retires_links_sent_to_the_old_one(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $member = User::factory()->create();

        LoginToken::query()->create([
            'user_id' => $member->getKey(),
            'token_hash' => str_repeat('a', 64),
            'purpose' => LoginTokenPurpose::MagicLink,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => 'verhuisd@example.com',
            ])
            ->assertOk();

        $this->assertSame(
            0,
            LoginToken::query()->where('user_id', $member->getKey())->count(),
            'A link in the old inbox would sign a stranger into this account.',
        );
    }

    #[Test]
    public function an_address_already_taken_is_a_conflict(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $member = User::factory()->create();
        $other = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => $other->email,
            ])
            ->assertConflict();
    }

    #[Test]
    public function moving_somebody_resets_their_role_and_ends_their_sessions(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $destination = Organization::factory()->create();
        $member = User::factory()->create();

        $this->tokenHeaders($member);

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => $member->email,
                'organizationId' => $destination->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('role', UserRole::Member->value)
            ->assertJsonPath('organizationId', $destination->getKey());

        $this->assertSame(
            0,
            $member->tokens()->count(),
            'A session opened inside one organization should not continue inside another.',
        );
    }

    #[Test]
    public function a_move_that_also_names_a_role_is_refused_rather_than_half_applied(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $destination = Organization::factory()->create();
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => $member->email,
                'role' => UserRole::Administrator->value,
                'organizationId' => $destination->getKey(),
            ])
            ->assertConflict();
    }

    #[Test]
    public function deleting_a_user_revokes_their_credentials_and_tells_them(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->for($admin->organization)->create();

        $this->tokenHeaders($member);

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/users/{$member->getKey()}")
            ->assertNoContent();

        $this->assertNotNull(User::withTrashed()->find($member->getKey())->deleted_at);
        $this->assertSame(0, $member->tokens()->count());
        Mail::assertSent(AccountDeletedMail::class);
    }

    #[Test]
    public function revoking_an_invitation_is_silent_and_leaves_no_row_behind(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $invited = User::factory()->invited()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/users/{$invited->getKey()}")
            ->assertNoContent();

        Mail::assertNothingSent();

        // Withdrawn, not marked: nobody ever signed in under this identifier, so a deleted row
        // would only stand in the way of the address it holds.
        $this->assertNull(User::withTrashed()->find($invited->getKey()));
        $this->assertSame(0, LoginToken::query()->where('user_id', $invited->getKey())->count());
    }

    #[Test]
    public function a_withdrawn_invitation_frees_the_address_for_a_fresh_one(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();
        $invited = User::factory()->invited()->for($admin->organization)->create();

        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)->deleteJson("/api/users/{$invited->getKey()}")->assertNoContent();

        $this->withHeaders($headers)
            ->postJson('/api/users/invitations', [
                'name' => 'Tweede Poging',
                'email' => $invited->email,
                'role' => UserRole::Member->value,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Tweede Poging');

        // A new row, not the old one brought back: there was nothing left to revive.
        $this->assertNotSame(
            $invited->getKey(),
            User::query()->where('normalized_email', User::normalize($invited->email))->sole()->getKey(),
        );
    }

    #[Test]
    public function somebody_who_once_signed_in_is_still_only_marked_when_their_re_invitation_is_withdrawn(): void
    {
        Mail::fake();
        $admin = User::factory()->administrator()->create();

        // Deleted and invited again, which is how a former user comes back: the status says
        // Invited, but their identifier is on rows elsewhere and the row has to survive.
        $former = User::factory()->deleted()->for($admin->organization)->create();

        $headers = $this->tokenHeaders($admin);

        $this->withHeaders($headers)
            ->postJson('/api/users/invitations', [
                'name' => $former->name,
                'email' => $former->email,
                'role' => UserRole::Member->value,
            ])
            ->assertCreated();

        $this->withHeaders($headers)->deleteJson("/api/users/{$former->getKey()}")->assertNoContent();

        $this->assertNotNull(User::withTrashed()->find($former->getKey())?->deleted_at);
    }

    #[Test]
    public function nobody_can_delete_their_own_account(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->administrator()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/users/{$admin->getKey()}")
            ->assertConflict()
            ->assertJsonPath('detail', 'Een gebruiker kan het eigen account niet verwijderen.');
    }

    #[Test]
    public function an_organization_cannot_be_left_without_an_administrator(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $onlyAdmin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->deleteJson("/api/users/{$onlyAdmin->getKey()}")
            ->assertConflict()
            ->assertJsonPath(
                'detail',
                'Een organisatie kan niet zonder beheerder achterblijven. Wijs eerst een andere beheerder aan.',
            );
    }

    #[Test]
    public function demoting_the_last_administrator_is_refused_too(): void
    {
        $platformAdmin = $this->platformAdministrator();
        $onlyAdmin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$onlyAdmin->getKey()}", [
                'name' => $onlyAdmin->name,
                'email' => $onlyAdmin->email,
                'role' => UserRole::Member->value,
            ])
            ->assertConflict();
    }

    #[Test]
    public function restoring_a_user_brings_them_back_without_their_credentials(): void
    {
        $admin = User::factory()->administrator()->create();
        $deleted = User::factory()->deleted()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/users/{$deleted->getKey()}/restore")
            ->assertOk()
            ->assertJsonPath('deletedUtc', null);

        $this->assertSame(0, LoginToken::query()->where('user_id', $deleted->getKey())->count());
    }

    #[Test]
    public function a_user_updates_their_own_name_but_nothing_else(): void
    {
        $member = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->putJson('/api/users/me', [
                'name' => 'Mijn Nieuwe Naam',
                'email' => 'poging@example.com',
                'role' => UserRole::PlatformAdministrator->value,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Mijn Nieuwe Naam')
            ->assertJsonPath('email', $member->email)
            ->assertJsonPath('role', UserRole::Member->value);
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
    }
}
