<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

$frontend = (string) parse_url((string) env('FRONTEND_URL', 'http://localhost:5173'), PHP_URL_HOST);
$frontendPort = parse_url((string) env('FRONTEND_URL', 'http://localhost:5173'), PHP_URL_PORT);

if ($frontend !== '' && is_int($frontendPort)) {
    $frontend .= ':'.$frontendPort;
}

// An empty SANCTUM_STATEFUL_DOMAINS means "the frontend", not "nothing": a deployment that leaves
// the line in place unset would otherwise silently stop the browser application signing in.
$configured = trim((string) env('SANCTUM_STATEFUL_DOMAINS', ''));

$stateful = array_filter(array_map(
    static fn (string $domain): string => trim($domain),
    explode(',', $configured === '' ? $frontend : $configured),
), static fn (string $domain): bool => $domain !== '');

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | The hosts whose requests get a session instead of being read for a bearer
    | token. This is the whole switch: a request arriving from one of these is
    | put through EnsureFrontendRequestsAreStateful's session and CSRF
    | middleware, and every other request reaches the API exactly as it did
    | before, with an Authorization header and nothing else.
    |
    | The default is the frontend this deployment already names in
    | FRONTEND_URL, host and port, because that is the one browser application
    | entitled to a cookie. Naming a host here trusts it with a signed-in
    | visitor's session, so the list stays as short as the deployment allows.
    |
    */

    'stateful' => array_values($stateful),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    // 30 days, matching the other backends. A client that has not called the API in a month signs
    // in again through their inbox, which costs them one email.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        /*
        | Sanctum's AuthenticateSession ends a session whose owner's password
        | has changed since it was opened. There are no passwords anywhere in
        | this application, so it has nothing to compare and would only write
        | an empty `password_hash_web` into every session. What actually ends a
        | session here is AuthenticationTokenService, which deletes the rows —
        | the same way a token is revoked.
        */
        'authenticate_session' => null,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
