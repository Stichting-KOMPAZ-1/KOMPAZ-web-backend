<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Mail\AccountDeletedMail;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_member_sees_only_their_own_organization(): void
    {
        $member = User::factory()->create();
        Organization::factory()->count(3)->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('totalCount', 1)
            ->assertJsonPath('items.0.id', $member->organization_id);
    }

    #[Test]
    public function a_platform_administrator_sees_them_all(): void
    {
        $platformAdmin = $this->platformAdministrator();
        Organization::factory()->count(3)->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('totalCount', 4);
    }

    #[Test]
    public function a_member_cannot_read_another_organization(): void
    {
        $member = User::factory()->create();
        $other = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($member))
            ->getJson("/api/organizations/{$other->getKey()}")
            ->assertForbidden()
            ->assertJsonPath('detail', 'Deze gebruiker hoort niet bij de opgevraagde organisatie.');
    }

    #[Test]
    public function a_platform_administrator_creates_an_organization(): void
    {
        $platformAdmin = $this->platformAdministrator();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/organizations', ['name' => 'Elkerliek'])
            ->assertCreated()
            ->assertJsonPath('name', 'Elkerliek')
            ->assertJsonPath('isPlatform', false)
            ->assertJsonPath('userCount', 0);
    }

    #[Test]
    public function an_administrator_cannot_create_an_organization(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/organizations', ['name' => 'Elkerliek'])
            ->assertForbidden();
    }

    #[Test]
    public function a_name_that_differs_only_by_case_is_refused(): void
    {
        $platformAdmin = $this->platformAdministrator();
        Organization::factory()->create([
            'name' => 'Elkerliek',
            'normalized_name' => Organization::normalize('Elkerliek'),
        ]);

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/organizations', ['name' => 'elkerliek'])
            ->assertConflict()
            ->assertJsonPath('detail', OrganizationMessages::NAME_TAKEN);
    }

    #[Test]
    public function creating_and_renaming_answer_a_taken_name_alike(): void
    {
        $platformAdmin = $this->platformAdministrator();
        Organization::factory()->create([
            'name' => 'Elkerliek',
            'normalized_name' => Organization::normalize('Elkerliek'),
        ]);
        $other = Organization::factory()->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/organizations/{$other->getKey()}", ['name' => 'ELKERLIEK'])
            ->assertConflict()
            ->assertJsonPath('detail', OrganizationMessages::NAME_TAKEN);
    }

    #[Test]
    public function a_missing_name_is_reported_with_the_forms_own_wording(): void
    {
        $platformAdmin = $this->platformAdministrator();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->postJson('/api/organizations', ['name' => ''])
            ->assertStatus(400)
            ->assertJsonPath('errors.name.0', OrganizationMessages::NAME_REQUIRED);
    }

    #[Test]
    public function an_administrator_renames_their_own_organization(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/organizations/{$admin->organization_id}", ['name' => 'Nieuwe Naam'])
            ->assertOk()
            ->assertJsonPath('name', 'Nieuwe Naam');
    }

    #[Test]
    public function the_platform_organization_cannot_be_deleted(): void
    {
        $platformAdmin = $this->platformAdministrator();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->deleteJson("/api/organizations/{$platformAdmin->organization_id}")
            ->assertConflict()
            ->assertJsonPath('detail', 'De organisatie die het platform beheert kan niet worden verwijderd.');
    }

    #[Test]
    public function deleting_an_organization_takes_its_users_and_tells_the_active_ones(): void
    {
        Mail::fake();
        $platformAdmin = $this->platformAdministrator();
        $doomed = Organization::factory()->create();

        User::factory()->for($doomed)->create();
        User::factory()->invited()->for($doomed)->create();

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->deleteJson("/api/organizations/{$doomed->getKey()}")
            ->assertNoContent();

        $this->assertNull(Organization::query()->find($doomed->getKey()));
        $this->assertSame(0, User::withTrashed()->where('organization_id', $doomed->getKey())->count());

        // Only the person who could actually sign in is told; the invitee never had an account.
        Mail::assertSent(AccountDeletedMail::class, 1);
    }

    #[Test]
    public function the_member_counts_leave_deleted_users_out(): void
    {
        $admin = User::factory()->administrator()->create();
        User::factory()->invited()->for($admin->organization)->create();
        User::factory()->deleted()->for($admin->organization)->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->getJson("/api/organizations/{$admin->organization_id}")
            ->assertOk()
            ->assertJsonPath('userCount', 2)
            ->assertJsonPath('activeUserCount', 1)
            ->assertJsonPath('invitedUserCount', 1);
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
    }
}
