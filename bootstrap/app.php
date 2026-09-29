<?php

declare(strict_types=1);

use App\Exceptions\Contracts\ProvidesProblemDetail;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureMinimumRole;
use App\Support\Errors\ProblemDetailFactory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered by name in AppServiceProvider, so which reaction belongs to which
    // event is readable in one place. Discovery would register each of them a second time, and
    // every notice would go out twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignTraceId::class);

        // The browser application signs in the same way a mobile client does, but keeps its
        // credential in a cookie rather than in storage a script can read. This puts Sanctum's
        // EnsureFrontendRequestsAreStateful in front of the api group, which starts a session and
        // validates a CSRF token for requests arriving from a domain named in `sanctum.stateful`,
        // and leaves every other request exactly as it was: bearer token, no session, no CSRF.
        //
        // Sanctum's guard already consults `sanctum.guard` before it looks at a bearer token, so
        // nothing else has to change for a cookie to authenticate an API call.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => EnsureMinimumRole::class,
        ]);

        // A guest who reaches the admin panel is sent to its emailed-link sign-in, not to a
        // password form that does not exist.
        $middleware->redirectGuestsTo(fn () => route('nova.sign-in'));

        // Behind a reverse proxy, every per-client decision keys on the proxy's address unless the
        // headers it sets are believed: the whole deployment would share one rate-limit partition,
        // and HTTPS redirection would loop. Which proxies are trusted is named per environment,
        // never guessed.
        // env() rather than config(): this closure runs while the application is being assembled,
        // which is before the configuration is loaded. It is the one place in the application that
        // reads the environment directly, and the reason bootstrap/ is not analysed for that rule.
        $proxies = (string) env('TRUSTED_PROXIES', '');

        $middleware->trustProxies(
            at: $proxies === '' ? [] : ($proxies === '*' ? '*' : explode(',', $proxies)),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Nova and the rest of the browser-facing side keep Laravel's own error pages; only the
        // API answers in problem details.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            // Nova asks for JSON on every request it makes, so `expectsJson()` alone swept the
            // whole panel in here — and a validation failure reached its forms as a 400 problem
            // detail instead of the 422 its frontend binds field errors from. That is the
            // difference between a dialog that stays open with the sentence under the input and
            // one that closes on a banner, which rule 18 turns on. The panel is a browser, not a
            // client of this API: it keeps Laravel's own shapes.
            //
            // Laravel's shapes, but not its guess at a status. The application's own refusals — not
            // your organization, not found — are exceptions Laravel does not know, and it answered
            // them with a 500. That reached nobody through Nova's own resources, which turn them
            // into banners first, and everybody through the panel's own routes under
            // `/nova-vendor`, which `nova*` matches too.
            if ($request->is('nova-api/*') || $request->is(trim((string) config('nova.path'), '/').'*')) {
                return $exception instanceof ProvidesProblemDetail
                    ? response()->json(['message' => $exception->getMessage()], $exception->problemStatus())
                    : null;
            }

            return ProblemDetailFactory::make($exception, (bool) config('app.debug'))
                ->toResponse($request);
        });
    })
    ->create();
