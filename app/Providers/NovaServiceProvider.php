<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Http\Controllers\Nova\NovaSignInController;
use App\Models\User;
use App\Nova\Organization;
use App\Nova\User as UserResource;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Laravel\Nova\Tool;

final class NovaServiceProvider extends NovaApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Nova::withBreadcrumbs();

        // There is no dashboard, so the panel opens on the roster rather than on Nova's
        // default '/dashboards/main', which is no longer a route.
        Nova::initialPath('/resources/'.UserResource::uriKey());

        Nova::mainMenu(fn (): array => [
            MenuSection::make('Beheer', [
                MenuItem::resource(UserResource::class),
                MenuItem::resource(Organization::class),
            ])->icon('users')->collapsable(),
        ]);
    }

    /**
     * Nova's own authentication routes are deliberately not registered.
     *
     * They serve a password form, and this application has no passwords. Signing in is
     * {@see NovaSignInController} instead, and unauthenticated visitors
     * are sent there by the redirect below.
     */
    protected function routes(): void
    {
        Nova::routes()
            // The panel's own login and logout, so Nova's "sign out" and its redirect for a
            // guest both land on the emailed-link flow rather than on a password form.
            ->withoutAuthenticationRoutes(login: '/beheer/inloggen', logout: '/beheer/afmelden')
            ->withoutPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Who reaches the panel at all.
     *
     * Only a platform administrator, checked against the row on every request rather than against
     * anything the session remembers — a demotion takes effect on the operator's next click, not
     * when their session happens to expire.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', static function (User $user): bool {
            return $user->role === UserRole::PlatformAdministrator && ! $user->isDeleted();
        });
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [];
    }
}
