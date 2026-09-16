<?php

declare(strict_types=1);

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

            return ProblemDetailFactory::make($exception, (bool) config('app.debug'))
                ->toResponse($request);
        });
    })
    ->create();
