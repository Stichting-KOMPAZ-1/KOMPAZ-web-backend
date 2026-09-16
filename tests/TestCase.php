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

        // A session survives, because a real browser request re-reads it and resolves the same
        // person; only the token guard is cleared, which is what a real request does by starting
        // with an empty container.
        $sessionUser = $auth->guard('web')->hasUser() ? $auth->guard('web')->user() : null;

        $auth->forgetGuards();

        if ($sessionUser !== null) {
            $auth->guard('web')->setUser($sessionUser);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
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
