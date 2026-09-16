<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| fortrabbit post-deploy
|--------------------------------------------------------------------------
|
| Runs once per release, on the build node, after Composer has finished.
|
| Migrations belong here rather than in the application's boot: several web
| processes start at once, and each of them migrating would be several
| writers racing through one schema. The build runs once, so this does too.
|
| Seeding runs every time on purpose. It plants the organization that runs
| the platform and its first administrator, and both are idempotent — without
| them a fresh deployment has nobody who can invite anybody.
|
*/

$steps = [
    // Every cache this script rebuilds is cleared first, not just the configuration. They live in
    // `bootstrap/cache`, which is sustained so that they survive to the running release at all,
    // and a sustained directory keeps whatever the last deploy left in it — so a release that
    // failed before rebuilding them would otherwise answer with the previous one's routes.
    'Clearing the configuration cache' => 'config:clear',
    'Clearing the route cache' => 'route:clear',
    'Clearing the event cache' => 'event:clear',
    'Clearing the view cache' => 'view:clear',
    // Nova ships its compiled assets inside the package and they are not in the repository.
    // Composer publishes them on `update`, which a deploy never runs, so a release would otherwise
    // serve a panel with no stylesheet — and, because `mix()` reads the manifest those assets
    // carry, no panel at all. `public/vendor` is sustained for this: a post-deploy script writes
    // on the build node, so anywhere else this would publish into a directory nothing serves.
    'Publishing package assets' => 'vendor:publish --tag=laravel-assets --force --no-interaction',
    'Migrating' => 'migrate --force --no-interaction',
    'Seeding' => 'db:seed --force --no-interaction',
    'Caching configuration' => 'config:cache',
    'Caching routes' => 'route:cache',
    'Caching views' => 'view:cache',
    'Caching events' => 'event:cache',
];

$failed = false;

foreach ($steps as $label => $command) {
    echo "==> {$label}\n";

    $output = [];
    $status = 0;
    exec('php artisan '.$command.' 2>&1', $output, $status);

    echo implode("\n", $output)."\n";

    if ($status !== 0) {
        // Reported rather than swallowed: a release whose migrations did not apply is a release
        // running against a schema it does not expect, and that should be visible in the deploy
        // log instead of at the first request.
        echo "!!! `{$command}` exited with status {$status}\n";
        $failed = true;

        break;
    }
}

exit($failed ? 1 : 0);
