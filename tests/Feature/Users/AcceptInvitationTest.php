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
 * this deployment actually serves. It is the same route every other emailed link lands on.
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
                route('nova.sign-in.claim').'?token=',
                $mail->link,
                'An invitation is accepted by this application, not by a page that may not be deployed.',
            );

            return true;
        });
    }

    /**
     * The link is itself a credential, so it signs its holder in rather than showing them a form
     * asking for the address they have just proved they can read.
     */
    #[Test]
    public function clicking_the_link_activates_an_operator_and_opens_the_dashboard(): void
    {
        $invitee = User::factory()->platformAdministrator()->invited()->create();

        $this->get(route('nova.sign-in.claim', ['token' => $this->invitationFor($invitee)]))
            ->assertRedirect(config('nova.path'));

        $invitee->refresh();
        $this->assertSame(UserStatus::Active, $invitee->status);
        $this->assertNotNull($invitee->activated_at);
        $this->assertAuthenticatedAs($invitee, 'web');
    }

    /**
     * Somebody the panel does not admit is still activated by the same click: accepting the
     * invitation and being let into the panel are two different questions, and only the second one
     * is answered by their role. Sending them on to the dashboard would trade a working link for a
     * 403 on the next page.
     */
    #[Test]
    public function clicking_the_link_activates_a_member_without_letting_them_into_the_panel(): void
    {
        $invitee = User::factory()->invited()->create();

        $this->get(route('nova.sign-in.claim', ['token' => $this->invitationFor($invitee)]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors(['email' => __('nova.sign_in.forbidden')]);

        $this->assertSame(UserStatus::Active, $invitee->refresh()->status);
        $this->assertGuest('web');
    }

    #[Test]
    public function a_link_that_ran_out_of_its_week_asks_for_a_new_invitation(): void
    {
        $invitee = User::factory()->invited()->create();
        $token = $this->invitationFor($invitee, Carbon::now()->subMinute());

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
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

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'));

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors(['email' => AuthenticationMessages::LINK_NOT_ACCEPTED]);
    }

    #[Test]
    public function an_unknown_secret_is_refused(): void
    {
        $this->get(route('nova.sign-in.claim', ['token' => 'not-a-real-secret']))
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
