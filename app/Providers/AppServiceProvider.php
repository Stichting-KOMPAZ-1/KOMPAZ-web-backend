<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Events\InvitationIssued;
use App\Events\MagicLinkIssued;
use App\Events\OrganizationLogoDiscarded;
use App\Events\UserDeleted;
use App\Listeners\DeleteDiscardedLogo;
use App\Listeners\SendAccountDeletedEmail;
use App\Listeners\SendInvitationEmail;
use App\Listeners\SendMagicLinkEmail;
use App\Services\AccessTokenIssuer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound as a singleton so that the auth manager and anything that asks for the guard by
        // name get the same object: the claims it parsed on this request are read by
        // EnsureAccountMatchesToken, which would otherwise be looking at a second, empty guard.
        $this->app->singleton(JwtGuard::class, fn ($app): JwtGuard => new JwtGuard(
            $app->make(AccessTokenIssuer::class),
            $app->make(Request::class),
        ));
    }

    public function boot(): void
    {
        $this->registerGuard();
        $this->registerRateLimiters();
        $this->registerEventListeners();
        $this->verifyConfiguration();
    }

    /**
     * The API's own guard. Sessions are Laravel's `web` guard and belong to Nova; a bearer token
     * is what every API request carries.
     */
    private function registerGuard(): void
    {
        Auth::extend('jwt', fn ($app): JwtGuard => $app->make(JwtGuard::class));
    }

    /**
     * Two budgets rather than one, partitioned by client address, which is all these endpoints know
     * before they have read a body.
     *
     * Shared by everyone behind one address, so a value tuned for one person locks out an office;
     * the numbers below are the ones the API has always used.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('magic-link', fn (Request $request) => Limit::perMinutes(
            self::windowMinutes('magic_link'),
            (int) config('kompaz.rate_limits.magic_link.attempts'),
        )->by($request->ip() ?? 'unknown'));

        RateLimiter::for('sign-in', fn (Request $request) => Limit::perMinutes(
            self::windowMinutes('sign_in'),
            (int) config('kompaz.rate_limits.sign_in.attempts'),
        )->by($request->ip() ?? 'unknown'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('kompaz.rate_limits.api.attempts'),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown'));
    }

    /** The configured window, in the whole minutes the limiter counts in. */
    private static function windowMinutes(string $limiter): int
    {
        return max(1, (int) ceil((int) config("kompaz.rate_limits.{$limiter}.window_seconds") / 60));
    }

    /**
     * Cross-cutting reactions are events, not service calls from the actions that cause them. Each
     * one is dispatched after its transaction commits, so a reaction never holds a database
     * transaction open across network I/O — and so a reaction is free to fail after the data is
     * safely committed.
     */
    private function registerEventListeners(): void
    {
        Event::listen(MagicLinkIssued::class, SendMagicLinkEmail::class);
        Event::listen(InvitationIssued::class, SendInvitationEmail::class);
        Event::listen(UserDeleted::class, SendAccountDeletedEmail::class);
        Event::listen(OrganizationLogoDiscarded::class, DeleteDiscardedLogo::class);
    }

    /**
     * Refuses to start on a configuration that would run insecurely rather than fail.
     *
     * Local development and the test suite are exempt, in that order of deliberateness: a checkout
     * should run, and a test should not have to invent a signing key to exercise something else.
     * Everywhere else, an absent secret is a deployment that forgot it.
     */
    private function verifyConfiguration(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        $signingKey = (string) config('kompaz.authentication.signing_key');

        if (strlen($signingKey) < 32) {
            throw new RuntimeException(
                'AUTH_SIGNING_KEY must be configured with at least 32 bytes of entropy.',
            );
        }

        $sliding = (int) config('kompaz.authentication.refresh_token_sliding_lifetime_days');
        $absolute = (int) config('kompaz.authentication.refresh_token_absolute_lifetime_days');

        if ($absolute < $sliding) {
            throw new RuntimeException(
                'AUTH_REFRESH_ABSOLUTE_LIFETIME_DAYS must be at least '
                .'AUTH_REFRESH_SLIDING_LIFETIME_DAYS, otherwise a session expires before its first refresh.',
            );
        }

        // The log mailer writes sign-in links into the log, which is a credential leak anywhere but
        // a developer machine. Never widen this to "any environment".
        if (config('mail.default') === 'log') {
            throw new RuntimeException(
                'MAIL_MAILER must be a real transport outside local development: the log mailer '
                .'writes sign-in links to the log.',
            );
        }

        // Uploaded files outlive one request and one container. The local disk does neither on a
        // platform whose filesystem is ephemeral, so a deployment that leaves it pointed there
        // loses every logo on the next deploy.
        if (in_array(config('kompaz.logo.disk'), ['local', 'public'], true)) {
            throw new RuntimeException(
                'LOGO_DISK must be an object-storage disk outside local development: the local '
                .'filesystem does not survive a deploy.',
            );
        }
    }
}
