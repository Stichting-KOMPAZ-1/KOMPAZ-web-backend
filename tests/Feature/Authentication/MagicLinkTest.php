<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserStatus;
use App\Mail\MagicLinkMail;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function asking_for_a_link_emails_one_that_signs_the_user_in(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/magic-link', ['email' => $user->email])
            ->assertNoContent();

        Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo($user->email));

        $this->assertSame(
            1,
            LoginToken::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', LoginTokenPurpose::MagicLink)
                ->whereNull('consumed_at')
                ->count(),
        );
    }

    /**
     * The address is the whole request, so an answer that varied with whether anybody holds it
     * would list who has an account to anybody willing to try addresses.
     */
    #[Test]
    public function an_unknown_address_is_answered_the_same_way_and_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/magic-link', ['email' => 'niemand@example.com'])
            ->assertNoContent();

        Mail::assertNothingSent();
        $this->assertSame(0, LoginToken::query()->count());
    }

    #[Test]
    public function a_deleted_user_is_passed_over_as_silently_as_an_unknown_one(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->delete();

        $this->postJson('/api/auth/magic-link', ['email' => $user->email])
            ->assertNoContent();

        Mail::assertNothingSent();
        $this->assertSame(0, LoginToken::query()->count());
    }

    /**
     * A link anybody can ask for must not be able to retire an invitation an administrator issued,
     * or a stranger could keep cancelling somebody's pending invitation by typing their address.
     */
    #[Test]
    public function asking_for_a_link_leaves_an_outstanding_invitation_alone(): void
    {
        Mail::fake();
        $user = User::factory()->invited()->create();
        $invitation = $this->issueLinkFor($user, LoginTokenPurpose::Invitation);

        $this->postJson('/api/auth/magic-link', ['email' => $user->email])
            ->assertNoContent();

        $this->postJson('/api/auth/tokens', ['token' => $invitation])->assertOk();
    }

    #[Test]
    public function an_invited_user_can_ask_for_a_link_and_is_activated_by_it(): void
    {
        Mail::fake();
        $user = User::factory()->invited()->create();

        $this->postJson('/api/auth/magic-link', ['email' => $user->email])
            ->assertNoContent();

        $token = $this->issueLinkFor($user);

        $this->postJson('/api/auth/tokens', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('user.status', UserStatus::Active->value);
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
