<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoginTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Signing in from the browser application, which keeps its credential in a cookie rather than in
 * storage a script can read.
 *
 * One sign-in, two credentials: redeeming a link always answers with a bearer token, and a request
 * that arrived from a host named in `sanctum.stateful` also leaves with a session. Which one a
 * caller gets is decided by where the request came from and never by anything in it, so every test
 * here turns the behaviour on with an Origin header and nothing else.
 */
final class BrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function redeeming_a_link_from_the_frontend_opens_a_session_that_reaches_the_api(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->frontendHeaders())
            ->postJson('/api/auth/tokens', ['token' => $this->linkFor($user)])
            ->assertOk()
            ->assertJsonPath('user.id', $user->getKey());

        // No Authorization header anywhere: the cookie the response set is the whole credential.
        $this->withHeaders($this->frontendHeaders())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->getKey());
    }

    /** The token half is untouched, which is what every client that is not a browser still uses. */
    #[Test]
    public function redeeming_a_link_from_the_frontend_still_answers_with_a_token(): void
    {
        $user = User::factory()->create();

        $token = $this->withHeaders($this->frontendHeaders())
            ->postJson('/api/auth/tokens', ['token' => $this->linkFor($user)])
            ->assertOk()
            ->json('token');

        $this->assertIsString($token);

        $this->flushSession();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->getKey());
    }

    /**
     * A caller that is not the browser application gets exactly what it got before: a token, no
     * session, no cookie, and no CSRF to think about.
     */
    #[Test]
    public function redeeming_a_link_from_anywhere_else_opens_no_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/tokens', ['token' => $this->linkFor($user)])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_request_from_an_unnamed_origin_gets_no_session(): void
    {
        $user = User::factory()->create();

        $this->withHeaders(['Origin' => 'https://ergens-anders.example'])
            ->postJson('/api/auth/tokens', ['token' => $this->linkFor($user)])
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * A role on a route is read from the user row on every request, so a cookie carries no more
     * claims than a token does.
     */
    #[Test]
    public function a_demotion_takes_effect_on_a_cookie_sessions_next_call(): void
    {
        $user = User::factory()->administrator()->create();

        $this->signInThroughTheBrowser($user);

        $this->withHeaders($this->frontendHeaders())->getJson('/api/users')->assertOk();

        $user->forceFill(['role' => UserRole::Member])->save();

        $this->withHeaders($this->frontendHeaders())->getJson('/api/users')->assertForbidden();
    }

    #[Test]
    public function signing_out_ends_the_session_in_every_browser(): void
    {
        $user = User::factory()->create();

        $this->signInThroughTheBrowser($user);

        // A second browser, standing in for the phone somebody left at the office.
        DB::table('sessions')->insert([
            'id' => 'een-andere-browser',
            'user_id' => $user->getKey(),
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->withHeaders($this->frontendHeaders())
            ->deleteJson('/api/auth/tokens/current')
            ->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());

        $this->withHeaders($this->frontendHeaders())->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function deleting_somebody_ends_the_session_they_were_signed_in_with(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->for($administrator->organization)->create();

        $this->signInThroughTheBrowser($user);
        $this->closeTheBrowser();

        $this->withHeaders($this->tokenHeaders($administrator))
            ->deleteJson('/api/users/'.$user->getKey())
            ->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /** A session opened inside one organization does not silently continue inside another. */
    #[Test]
    public function moving_somebody_ends_the_session_they_were_signed_in_with(): void
    {
        $operator = User::factory()->platformAdministrator()->create();
        $user = User::factory()->create();
        $destination = Organization::factory()->create();

        $this->signInThroughTheBrowser($user);
        $this->closeTheBrowser();

        $this->withHeaders($this->tokenHeaders($operator))
            ->putJson('/api/users/'.$user->getKey(), [
                'name' => $user->name,
                'email' => $user->email,
                'organizationId' => $destination->getKey(),
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * A session renews itself on every request, so there is nothing here it needs — and minting a
     * token for it would turn this endpoint into a way to convert a session into a credential that
     * outlives it.
     */
    #[Test]
    public function a_cookie_session_cannot_refresh_itself_into_a_token(): void
    {
        $user = User::factory()->create();

        $this->signInThroughTheBrowser($user);

        $this->withHeaders($this->frontendHeaders())
            ->postJson('/api/auth/tokens/refresh')
            ->assertStatus(409)
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('detail', 'Deze sessie werkt met een cookie en heeft geen token dat vernieuwd kan worden.');
    }

    /** Signs in through the browser application and leaves the session in place. */
    private function signInThroughTheBrowser(User $user): void
    {
        $this->withHeaders($this->frontendHeaders())
            ->postJson('/api/auth/tokens', ['token' => $this->linkFor($user)])
            ->assertOk();

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * Walks away from the browser that was just signed in, so the next request is somebody else's.
     *
     * The session store is a container singleton and one test makes every request through one
     * container, so without this an administrator's token request would be answered as the person
     * signed in above — Sanctum consults the session guard before it reads a bearer token. A real
     * request from another machine starts with an empty store, which is what this restores.
     */
    private function closeTheBrowser(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function linkFor(User $user): string
    {
        return app(LoginTokenIssuer::class)->issue($user, LoginTokenPurpose::MagicLink);
    }
}
