<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Mail\NovaSignInMail;
use App\Models\LoginToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\SecretTokenFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NovaAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_panel_sends_a_guest_to_its_own_sign_in(): void
    {
        $this->get('/nova')->assertRedirect(route('nova.sign-in'));
    }

    #[Test]
    public function the_sign_in_page_renders(): void
    {
        $this->get(route('nova.sign-in'))
            ->assertOk()
            ->assertSee(__('nova.sign_in.title'));
    }

    #[Test]
    public function it_emails_a_link_to_a_platform_administrator(): void
    {
        Mail::fake();
        $operator = $this->platformAdministrator();

        $this->post(route('nova.sign-in.send'), ['email' => $operator->email])
            ->assertRedirect(route('nova.sign-in'));

        Mail::assertSent(NovaSignInMail::class);
    }

    #[Test]
    public function it_answers_the_same_way_for_somebody_who_is_not_an_operator(): void
    {
        Mail::fake();
        $member = User::factory()->create();

        $this->post(route('nova.sign-in.send'), ['email' => $member->email])
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHas('status', __('nova.sign_in.sent'));

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_valid_link_opens_a_session_on_the_panel(): void
    {
        $operator = $this->platformAdministrator();
        $token = $this->linkFor($operator);

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
            ->assertRedirect(config('nova.path'));

        $this->assertAuthenticatedAs($operator->fresh(), 'web');
    }

    #[Test]
    public function a_link_belonging_to_somebody_demoted_since_it_was_sent_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $token = $this->linkFor($operator);

        // A link lives thirty minutes, and somebody can be demoted inside that window.
        $operator->role = UserRole::Administrator;
        $operator->save();

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    public function a_spent_link_is_refused(): void
    {
        $operator = $this->platformAdministrator();
        $token = $this->linkFor($operator);

        $this->get(route('nova.sign-in.claim', ['token' => $token]));
        $this->post(route('nova.sign-out'));

        $this->get(route('nova.sign-in.claim', ['token' => $token]))
            ->assertRedirect(route('nova.sign-in'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    public function an_administrator_who_signs_in_cannot_reach_the_panel(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin, 'web')->get('/nova')->assertForbidden();
    }

    private function platformAdministrator(): User
    {
        return User::factory()->platformAdministrator()
            ->for(Organization::factory()->platform())
            ->create();
    }

    private function linkFor(User $user): string
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
}
