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

    /* The timezone used for human-readable deadlines in email. Database timestamps stay in UTC. */
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Amsterdam'),

    /*
    | Where the public browser-facing application lives. A magic link asked for
    | through the API points at it; invitations and the panel's own link are
    | spent on this application, at the route named `nova.sign-in.claim`. This
    | is also what CORS and `sanctum.stateful` are built from, and what a test
    | uses as its Origin.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

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
    | The organization that runs the platform, and its first administrators: one address or
    | several, comma-separated. Read by the seeder, which runs on every deploy and is idempotent.
    */
    'seed' => [
        'platform_organization' => env('SEED_PLATFORM_ORGANIZATION', 'KOMPAZ'),
        'platform_administrator_emails' => env('SEED_PLATFORM_ADMINISTRATOR_EMAIL', ''),
        'platform_administrator_name' => env('SEED_PLATFORM_ADMINISTRATOR_NAME', 'Platformbeheerder'),
    ],

    /*
    | Whether the generated API documentation at `/docs/api` may be read without
    | signing in. Off in production, so a deployment has to say yes on purpose;
    | a signed-in platform administrator reaches it either way, which is what
    | makes leaving this off workable rather than merely strict.
    */
    'api_docs_public' => (bool) env('API_DOCS_PUBLIC', env('APP_ENV', 'production') !== 'production'),

    /*
    | How much an operator may hang off one module. The product picked ten for
    | videos and for links; contacts had no answer, and ten is the same number
    | for the same reason — a list nobody can read is not a longer list, it is
    | a worse one. Config rather than constants because these are the product's
    | numbers, not the schema's: a count is not expressible as a constraint on
    | a row, so the form is the only place that can refuse one.
    */
    'modules' => [
        'maximum_videos' => (int) env('MODULE_MAXIMUM_VIDEOS', 10),
        'maximum_links' => (int) env('MODULE_MAXIMUM_LINKS', 10),
        'maximum_contacts' => (int) env('MODULE_MAXIMUM_CONTACTS', 10),
    ],

    /*
    | Uploaded videos. The size is the product's number; the column holding it
    | is an unsigned integer and would take twice this. The browser uploads in
    | blocks of `block_size_bytes`, which is what lets a dropped connection cost
    | one block rather than the whole file.
    |
    | An upload link lives long enough for a slow connection to finish two
    | gigabytes. A playback link is checked by Azure on every range the player
    | asks for, so it has to outlast somebody watching, pausing and seeking —
    | one that expired halfway would stop the video, not merely the download.
    */
    'videos' => [
        'maximum_size_bytes' => 2 * 1024 * 1024 * 1024,
        'block_size_bytes' => 8 * 1024 * 1024,
        'upload_minutes' => (int) env('VIDEO_UPLOAD_MINUTES', 360),
        'playback_minutes' => (int) env('VIDEO_PLAYBACK_MINUTES', 240),
    ],

    'logo' => [
        /*
        | The largest logo accepted. Generous for a logo, and small enough to
        | hold in memory while the format is read out of the bytes.
        */
        'maximum_size_bytes' => 10 * 1024 * 1024,
    ],
];
