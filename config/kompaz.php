<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KOMPAZ application settings
|--------------------------------------------------------------------------
|
| Everything the product decides for itself, rather than what a framework or
| a host decides. Secrets are deliberately absent from the defaults: a
| deployment that forgets to supply one fails at startup instead of running
| on a value that is in the repository.
|
*/

return [

    /*
    | Where the browser-facing application lives. Sign-in links point at it,
    | so an empty value outside local development is a link to nowhere.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    | The path on the frontend that redeems the secret from a sign-in link.
    */
    'sign_in_path' => env('FRONTEND_SIGN_IN_PATH', '/inloggen'),

    /*
    | How long the two kinds of emailed link stay redeemable. How long a session
    | then lasts is Sanctum's `expiration`, in config/sanctum.php.
    */
    'authentication' => [

        'magic_link_lifetime_minutes' => (int) env('AUTH_MAGIC_LINK_LIFETIME_MINUTES', 30),

        'invitation_lifetime_days' => (int) env('AUTH_INVITATION_LIFETIME_DAYS', 7),
    ],

    /*
    | Rate limits, per client address — all these endpoints know before they have read a body.
    | Shared by everyone behind one address, so a value tuned for one person locks out an office.
    */
    'rate_limits' => [
        // Asking for a link is the expensive half: it sends email. It gets a bucket of its own so
        // that retrying cannot exhaust the allowance the resulting click needs to spend.
        'magic_link' => [
            'attempts' => (int) env('RATE_LIMIT_MAGIC_LINK_ATTEMPTS', 5),
            'window_seconds' => (int) env('RATE_LIMIT_MAGIC_LINK_WINDOW_SECONDS', 300),
        ],
        // The token endpoints send no email, so the budget is about abuse rather than anyone's
        // inbox, and is correspondingly wider.
        'sign_in' => [
            'attempts' => (int) env('RATE_LIMIT_SIGN_IN_ATTEMPTS', 30),
            'window_seconds' => (int) env('RATE_LIMIT_SIGN_IN_WINDOW_SECONDS', 300),
        ],
        'api' => [
            'attempts' => (int) env('RATE_LIMIT_API_ATTEMPTS', 100),
        ],
    ],

    /*
    | The organization that runs the platform, and its first administrator. Read by the seeder,
    | which runs on every deploy and is idempotent.
    */
    'seed' => [
        'platform_organization' => env('SEED_PLATFORM_ORGANIZATION', 'KOMPAZ'),
        'platform_administrator_email' => env('SEED_PLATFORM_ADMINISTRATOR_EMAIL', ''),
        'platform_administrator_name' => env('SEED_PLATFORM_ADMINISTRATOR_NAME', 'Platformbeheerder'),
    ],

    /*
    | Whether the generated API documentation at `/docs/api` may be read without
    | signing in. Off in production, so a deployment has to say yes on purpose;
    | a signed-in platform administrator reaches it either way, which is what
    | makes leaving this off workable rather than merely strict.
    */
    'api_docs_public' => (bool) env('API_DOCS_PUBLIC', env('APP_ENV', 'production') !== 'production'),

    'logo' => [
        /*
        | The largest logo accepted. Generous for a logo, and small enough to
        | hold in memory while the format is read out of the bytes.
        */
        'maximum_size_bytes' => 10 * 1024 * 1024,
    ],
];
