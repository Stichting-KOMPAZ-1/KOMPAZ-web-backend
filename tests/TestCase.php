<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Services\AuthenticationTokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Uploads go to a disk that is thrown away with the test, so nothing a test writes can
        // reach the developer's own storage directory.
        Storage::fake();
    }

    /**
     * Makes a request, forgetting whoever the last one resolved.
     *
     * The token guard caches the user it resolved and the container is shared across every request
     * a single test makes, so without this a second request happily reuses the first one's caller —
     * even after their token has been revoked or their role changed. A real request always starts
     * with a fresh container, so this restores the behaviour under test rather than changing it.
     */
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $auth = $this->app['auth'];

        // Who was signed in through the browser, by identifier rather than by object. A session
        // survives a request — a real browser sends the cookie again — but the row behind it is
        // read afresh every time, and that re-read is what a demotion or a deletion interrupts.
        // Keeping the object instead would carry the role and the deleted flag it had a request
        // ago, which is the very thing several tests here are about.
        $signedIn = $auth->guard('web')->id();

        $auth->forgetGuards();

        // `auth.driver` is a container singleton and Illuminate\Contracts\Auth\Guard is an alias
        // for it, so one request's guard would otherwise be handed to the next one's session
        // handler — which stamps the row it writes with whoever that guard had already resolved.
        // A real request resolves it once, in a container of its own.
        $this->app->forgetInstance('auth.driver');

        if ($signedIn !== null) {
            $user = $auth->guard('web')->getProvider()->retrieveById($signedIn);

            if ($user !== null) {
                $auth->guard('web')->setUser($user);
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * The headers that make a request one from the browser application.
     *
     * Sanctum decides whether to start a session by matching the Origin (or Referer) against
     * `sanctum.stateful`, so this is the whole difference between a request that signs in with a
     * cookie and one that signs in with a bearer token.
     *
     * @return array<string, string>
     */
    protected function frontendHeaders(): array
    {
        return ['Origin' => rtrim((string) config('kompaz.frontend_url'), '/')];
    }

    /**
     * The headers a signed-in caller sends.
     *
     * A real token issued the way sign-in issues one, rather than `actingAs`: that exercises the
     * guard, the token lookup and the middleware a request actually goes through.
     *
     * @return array<string, string>
     */
    protected function tokenHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.app(AuthenticationTokenService::class)->issue($user)->token];
    }
}
