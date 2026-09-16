<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserStatus;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_public_api_does_not_offer_magic_links(): void
    {
        $this->postJson('/api/auth/magic-link', ['email' => 'iemand@example.com'])
            ->assertNotFound();
    }

    #[Test]
    public function redeeming_a_link_signs_the_user_in_and_activates_an_invitee(): void
    {
        $user = User::factory()->invited()->create();
        $token = $this->issueLinkFor($user);

        $response = $this->postJson('/api/auth/tokens', ['token' => $token])->assertOk();

        $response->assertJsonStructure([
            'token', 'tokenType',
            'user' => ['id', 'email', 'name', 'role', 'status'],
        ]);
        $this->assertSame('Bearer', $response->json('tokenType'));
        $this->assertSame(UserStatus::Active->value, $response->json('user.status'));
        $this->assertNotNull($user->refresh()->activated_at);
        $this->assertNotNull($user->last_login_at);
    }

    #[Test]
    public function a_link_cannot_be_redeemed_twice(): void
    {
        $user = User::factory()->create();
        $token = $this->issueLinkFor($user);

        $this->postJson('/api/auth/tokens', ['token' => $token])->assertOk();

        $this->postJson('/api/auth/tokens', ['token' => $token])
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Deze inloglink is ongeldig, al gebruikt of verlopen.');
    }

    #[Test]
    public function an_expired_link_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->issueLinkFor($user);

        LoginToken::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/auth/tokens', ['token' => $token])->assertUnauthorized();
    }

    #[Test]
    public function an_unknown_link_is_refused_the_same_way_a_spent_one_is(): void
    {
        $this->postJson('/api/auth/tokens', ['token' => 'not-a-real-secret'])
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Deze inloglink is ongeldig, al gebruikt of verlopen.');
    }

    #[Test]
    public function accepting_an_invitation_spends_every_outstanding_invitation(): void
    {
        $user = User::factory()->invited()->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => app(SecretTokenFactory::class)->hash('stale-invitation'),
            'purpose' => LoginTokenPurpose::Invitation,
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        $token = $this->issueLinkFor($user);
        $this->postJson('/api/auth/tokens', ['token' => $token])->assertOk();

        $this->assertSame(
            0,
            LoginToken::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', LoginTokenPurpose::Invitation)
                ->whereNull('consumed_at')
                ->count(),
            'A week-long invitation must not outlive the sign-in that accepted it.',
        );
    }

    private function issueLinkFor(User $user, LoginTokenPurpose $purpose = LoginTokenPurpose::MagicLink): string
    {
        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes(30),
        ]);

        return $secret->value;
    }
}
