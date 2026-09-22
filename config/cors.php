<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Published rather than left to the framework's defaults, because those are
| `allowed_origins: ['*']` with `supports_credentials: false` — the one pair
| a browser accepts precisely as long as no cookie is involved. A session
| cookie makes the request credentialed, and a credentialed request is
| refused against a wildcard origin, so the origin has to be named.
|
| It is the frontend's own address and nothing else: naming an origin here is
| what lets a page on it read this API's answers, so a second entry would be
| a second application trusted with everything a signed-in visitor can see.
|
*/

// env() rather than config('kompaz.frontend_url'): configuration files are loaded in
// alphabetical order, so `kompaz` does not exist yet while this one is being read.
$frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');

return [

    /*
    | `sanctum/csrf-cookie` is here alongside the API because the browser
    | application fetches it before its first write, and that request carries
    | the session cookie like any other.
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $frontend === '' ? [] : [$frontend],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
    | The trace identifier AssignTraceId puts on every response, so the browser
    | application can quote it in a report. A cross-origin caller cannot read a
    | response header it is not handed explicitly, whatever the server sends.
    */
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    /*
    | What the whole file is for: without this the browser neither sends the
    | session cookie nor keeps the one this API sets.
    */
    'supports_credentials' => true,

];
