<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a token is worth, and for how long.
 *
 * Sanctum reads the user row on every request, so there are no claims to go stale: deleting or
 * demoting somebody takes effect on their very next call, with nothing to compare.
 */
final class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_valid_token_reaches_the_api(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($user))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->getKey());
    }

    #[Test]
    public function a_request_without_a_token_is_refused(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function an_invented_token_is_refused(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer 1|totally-made-up'])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function only_the_hash_of_a_token_is_stored(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenHeaders($user)['Authorization'];

        $stored = DB::table('personal_access_tokens')->value('token');

        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString((string) $stored, $token);
    }

    #[Test]
    public function refreshing_swaps_the_token_for_a_new_one(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $fresh = $this->withHeaders($headers)
            ->postJson('/api/auth/tokens/refresh')
            ->assertOk()
            ->json('token');

        $this->assertNotEmpty($fresh);

        // The one that was swapped out is gone, so a client that kept a copy cannot keep using it.
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();

        $this->withHeaders(['Authorization' => 'Bearer '.$fresh])
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    #[Test]
    public function refreshing_needs_a_valid_token_of_its_own(): void
    {
        $this->postJson('/api/auth/tokens/refresh')->assertUnauthorized();
    }

    #[Test]
    public function signing_out_ends_every_session_on_every_device(): void
    {
        $user = User::factory()->create();
        $phone = $this->tokenHeaders($user);
        $laptop = $this->tokenHeaders($user);

        $this->withHeaders($laptop)->deleteJson('/api/auth/tokens/current')->assertNoContent();

        $this->withHeaders($laptop)->getJson('/api/auth/me')->assertUnauthorized();
        $this->withHeaders($phone)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_deleted_user_loses_access_immediately(): void
    {
        $admin = User::factory()->administrator()->create();
        $victim = User::factory()->for($admin->organization)->create();
        $victimHeaders = $this->tokenHeaders($victim);

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/users/{$victim->getKey()}")
            ->assertNoContent();

        $this->withHeaders($victimHeaders)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_demotion_takes_effect_on_the_next_request(): void
    {
        $user = User::factory()->administrator()->create();
        $headers = $this->tokenHeaders($user);

        $this->withHeaders($headers)->getJson('/api/users')->assertOk();

        $user->role = UserRole::Member;
        $user->save();

        // No comparison and no round trip: the row is what the guard reads.
        $this->withHeaders($headers)->getJson('/api/users')->assertForbidden();
    }

    #[Test]
    public function moving_somebody_between_organizations_ends_their_sessions(): void
    {
        $platformAdmin = User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
        $member = User::factory()->create();
        $memberHeaders = $this->tokenHeaders($member);

        $this->withHeaders($this->tokenHeaders($platformAdmin))
            ->putJson("/api/users/{$member->getKey()}", [
                'name' => $member->name,
                'email' => $member->email,
                'organizationId' => Organization::factory()->create()->getKey(),
            ])
            ->assertOk();

        $this->withHeaders($memberHeaders)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
