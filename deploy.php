<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| fortrabbit post-deploy
|--------------------------------------------------------------------------
|
| Runs once per release, on the build node, after Composer has finished.
|
| Only what this script changes *outside its own filesystem* reaches the
| running application. The database is shared, so migrating and seeding here
| work; every file it writes is written on the build node and thrown away,
| and `sustained` in fortrabbit.yml does not change that — it carries the
| running app's own directories from one release to the next, and shares
| nothing with the build.
|
| That is why nothing else lives here:
|
| - Nova's assets are published by Composer (`post-install-cmd`), the only
|   phase whose file writes become part of the release. Publishing them from
|   this script reported success and shipped nothing, which left every panel
|   page raising "Mix manifest not found".
| - The configuration, route and event caches are not warmed at all. They
|   would be written to a `bootstrap/cache` nothing serves, and warming them
|   here would be wrong even if it landed: fortrabbit injects the runtime
|   environment into the web processes, not into the build, so a config cache
|   built here would bake whatever the build node happened to see. The app
|   therefore runs with configuration uncached, which is a cost in boot time
|   and the reason `env()` outside `config/` still resolves in production.
| - Views are not precompiled, because Blade compiles them on first render
|   into `storage`, which is sustained.
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
    'Migrating' => 'migrate --force --no-interaction',
    'Seeding' => 'db:seed --force --no-interaction',
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
