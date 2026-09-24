<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\LoginToken;
use App\Models\User;
use App\Services\SecretTokenFactory;
use App\Support\Auth\AuthenticationMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens between the invitation email and the invitee's first sign-in.
 *
 * The link lands on this application rather than on the frontend, so accepting an invitation works
 * before the frontend exists and a link that ran out of its week can be answered in Dutch on a page
 * this deployment actually serves.
 */
final class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_invitation_names_the_platform_rather_than_the_organization(): void
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

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
            $this->assertSame(
                'Uitnodiging om deel te nemen aan het ZelfZorg-platform',
                $mail->envelope()->subject,
            );

            $this->assertStringStartsWith(
                route('invitation.accept').'?token=',
                $mail->link,
                'An invitation is accepted by this application, not by a page that may not be deployed.',
            );

            return true;
        });
    }

    #[Test]
    public function clicking_the_link_activates_the_invitee_and_lands_on_the_sign_in_screen(): void
    {
        $invitee = User::factory()->invited()->create();

        $this->get(route('invitation.accept', ['token' => $this->invitationFor($invitee)]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHas('status', __('nova.sign_in.invitation_accepted'));

        $invitee->refresh();
        $this->assertSame(UserStatus::Active, $invitee->status);
        $this->assertNotNull($invitee->activated_at);
    }

    #[Test]
    public function a_link_that_ran_out_of_its_week_asks_for_a_new_invitation(): void
    {
        $invitee = User::factory()->invited()->create();
        $token = $this->invitationFor($invitee, Carbon::now()->subMinute());

        $this->get(route('invitation.accept', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors(['email' => AuthenticationMessages::INVITATION_EXPIRED]);

        $this->assertSame(UserStatus::Invited, $invitee->refresh()->status);
    }

    /**
     * The API redeems the same secret and has to say the same thing, or the invitee is told one
     * story by the email's landing page and another by the application they were invited to.
     */
    #[Test]
    public function the_api_answers_an_expired_invitation_the_same_way(): void
    {
        $invitee = User::factory()->invited()->create();
        $token = $this->invitationFor($invitee, Carbon::now()->subMinute());

        $this->postJson('/api/auth/tokens', ['token' => $token])
            ->assertUnauthorized()
            ->assertJsonPath('detail', AuthenticationMessages::INVITATION_EXPIRED);
    }

    /**
     * A link that was already spent is not an expired one, and must not be reported as one: telling
     * somebody who just accepted to go and ask for another invitation would send them to an
     * administrator for nothing.
     */
    #[Test]
    public function a_second_click_is_refused_without_claiming_the_link_expired(): void
    {
        $invitee = User::factory()->invited()->create();
        $token = $this->invitationFor($invitee);

        $this->get(route('invitation.accept', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'));

        $this->get(route('invitation.accept', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors(['email' => AuthenticationMessages::LINK_NOT_ACCEPTED]);
    }

    #[Test]
    public function an_unknown_secret_is_refused(): void
    {
        $this->get(route('invitation.accept', ['token' => 'not-a-real-secret']))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors(['email' => AuthenticationMessages::LINK_NOT_ACCEPTED]);
    }

    private function invitationFor(User $user, ?Carbon $expiresAt = null): string
    {
        $secret = app(SecretTokenFactory::class)->create();

        LoginToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $secret->hash,
            'purpose' => LoginTokenPurpose::Invitation,
            'expires_at' => $expiresAt ?? Carbon::now()->addDays(7),
        ]);

        return $secret->value;
    }
}
