<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An access token outlives the change to the account it describes. These are the cases where that
 * would otherwise let somebody keep acting on rights they no longer hold.
 */
final class AccountStatusTest extends TestCase
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
    public function a_token_signed_with_another_key_is_refused(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        config(['kompaz.authentication.signing_key' => str_repeat('a-different-key-', 4)]);

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_deleted_user_loses_access_before_their_token_expires(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $user->delete();

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_demotion_takes_effect_on_the_next_request(): void
    {
        $user = User::factory()->administrator()->create();
        $headers = $this->tokenHeaders($user);

        // The token still says Administrator; the row no longer does.
        $user->role = UserRole::Member;
        $user->save();

        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath(
                'detail',
                'Dit token is uitgegeven voor een rol of organisatie die het account niet meer heeft.',
            );
    }

    #[Test]
    public function a_move_between_organizations_takes_effect_on_the_next_request(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $user->organization_id = Organization::factory()->create()->getKey();
        $user->save();

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_promotion_also_invalidates_the_token_it_predates(): void
    {
        $user = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $user->role = UserRole::Administrator;
        $user->save();

        // The comparison is an equality, not a floor: a token that disagrees with the row is
        // wrong whichever direction it disagrees in, and one round trip puts it right.
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
