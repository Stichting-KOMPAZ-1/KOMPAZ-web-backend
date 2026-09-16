<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\InvitationIssued;
use App\Events\OrganizationLogoDiscarded;
use App\Events\UserDeleted;
use App\Listeners\DeleteDiscardedLogo;
use App\Listeners\SendAccountDeletedEmail;
use App\Listeners\SendInvitationEmail;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerApiDocsGate();
        $this->registerEventListeners();
        $this->verifyConfiguration();
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

    /**
     * Who may read the generated API documentation at `/docs/api`.
     *
     * Scramble restricts it to local development unless something says otherwise. Two things can:
     * the flag, for a deployment that wants the documentation open, and being a platform
     * administrator — they are the only people with a session on this application at all, so
     * letting them read it costs nothing and means the flag can stay off.
     */
    private function registerApiDocsGate(): void
    {
        Gate::define('viewApiDocs', static function (?User $user): bool {
            return (bool) config('kompaz.api_docs_public')
                || $user?->role === UserRole::PlatformAdministrator;
        });
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
        Event::listen(InvitationIssued::class, SendInvitationEmail::class);
        Event::listen(UserDeleted::class, SendAccountDeletedEmail::class);
        Event::listen(OrganizationLogoDiscarded::class, DeleteDiscardedLogo::class);
    }

    /**
     * Refuses to start on a configuration that would run insecurely rather than fail.
     *
     * Local development and the test suite are exempt, in that order of deliberateness: a checkout
     * should run, and a test should not have to supply a mail relay to exercise something else.
     * Everywhere else, an absent secret is a deployment that forgot it.
     */
    private function verifyConfiguration(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        // The log mailer writes sign-in links into the log, which is a credential leak anywhere but
        // a developer machine. Never widen this to "any environment".
        if (config('mail.default') === 'log') {
            throw new RuntimeException(
                'MAIL_MAILER must be a real transport outside local development: the log mailer '
                .'writes sign-in links to the log.',
            );
        }
    }
}
