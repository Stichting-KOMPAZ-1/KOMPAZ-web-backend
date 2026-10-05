<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\UserRole;
use App\Http\Controllers\Nova\NovaReorderController;
use App\Http\Controllers\Nova\NovaSignInController;
use App\Http\Controllers\Nova\NovaVideoPreviewController;
use App\Http\Controllers\Nova\Pages\NestedResourceCreateController;
use App\Http\Controllers\Nova\Pages\NestedResourceDetailController;
use App\Http\Controllers\Nova\Pages\NestedResourceUpdateController;
use App\Http\Controllers\VideoUploadController;
use App\Models\User;
use App\Nova\ELearning as ELearningResource;
use App\Nova\Module as ModuleResource;
use App\Nova\ModuleActivation as ModuleActivationResource;
use App\Nova\ModuleCategory as ModuleCategoryResource;
use App\Nova\Organization;
use App\Nova\User as UserResource;
use App\Support\Access\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Nova\Http\Controllers\Pages\ResourceCreateController;
use Laravel\Nova\Http\Controllers\Pages\ResourceDetailController;
use Laravel\Nova\Http\Controllers\Pages\ResourceUpdateController;
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

        // The drag-and-drop for a course's chapters and a chapter's steps. Only the package's
        // script and stylesheet: its own provider is not discovered, and the addresses they post
        // to are answered by {@see NovaReorderController} — see routes() for why.
        Nova::script('nova-sortable', base_path('vendor/outl1ne/nova-sortable/dist/js/entry.js'));
        Nova::style('nova-sortable', base_path('vendor/outl1ne/nova-sortable/dist/css/tool.css'));

        // This application's own frontend: the module form's searchable checkbox list, and the
        // fix that makes a dragged table read its rows back. After nova-sortable's script, whose
        // table it extends. Built in resources/nova/panel and committed: the deploy builds nothing.
        Nova::script('kompaz-panel', resource_path('nova/panel/dist/js/panel.js'));

        // The rich-text editor's attach button, which Trix draws even though no field here accepts
        // a file. Plain CSS beside the script's sources, so it needs no build.
        Nova::style('kompaz-panel', resource_path('nova/panel/panel.css'));

        // Nova's default footer credits Laravel and shows its version; the panel carries the
        // product's own name instead, in the same markup.
        Nova::footer(fn (): string => '<p class="text-center">&copy; '.now()->year.' KOMPAZ</p>');

        // There is no dashboard, so the panel opens on the roster rather than on Nova's
        // default '/dashboards/main', which is no longer a route.
        Nova::initialPath('/resources/'.UserResource::uriKey());

        // Two entries are called "Modules" and only one of them is ever shown: a platform
        // administrator writes modules, an organization administrator fills in their own copy of
        // one. Each resource answers `authorizedToViewAny` for exactly one of them, and Nova drops
        // a menu item the operator may not see.
        Nova::mainMenu(fn (): array => [
            MenuSection::make('Beheer', [
                MenuItem::resource(UserResource::class),
                // An organization administrator has one organization, reached from their own
                // users' rows; a list of one is not worth a place in the menu.
                MenuItem::resource(Organization::class)
                    ->canSee(static function (Request $request): bool {
                        $operator = $request->user();

                        return $operator instanceof User && OrganizationAccess::isPlatformAdministrator($operator);
                    }),
                MenuItem::resource(ModuleResource::class),
                MenuItem::resource(ModuleCategoryResource::class),
                MenuItem::resource(ModuleActivationResource::class),
                MenuItem::resource(ELearningResource::class),
            ])->icon('users')->collapsable(),
        ]);
    }

    /**
     * Nova's page controllers, with breadcrumbs that show the whole path to a nested record.
     *
     * Nova builds breadcrumbs inside these controllers and offers no hook on the resource, so the
     * three pages a course, chapter or step is read and written on are swapped for subclasses.
     * Nova registers its routes by class name and the container resolves them, which is what makes
     * a binding enough.
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(ResourceDetailController::class, NestedResourceDetailController::class);
        $this->app->bind(ResourceCreateController::class, NestedResourceCreateController::class);
        $this->app->bind(ResourceUpdateController::class, NestedResourceUpdateController::class);
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

        // The paths the drag-and-drop script posts to, behind Nova's own authentication and its
        // `viewNova` gate. The package's controller for them is not used: it authenticates nobody
        // and calls whatever relationship method the request names. The use case behind these
        // asks for a platform administrator again, and the controller knows two lists only.
        Route::middleware('nova:api')
            ->domain(config('nova.domain'))
            ->prefix('nova-vendor/nova-sortable/sort/{resource}')
            ->group(static function (): void {
                Route::post('update-order', [NovaReorderController::class, 'updateOrder']);
                Route::post('move-to-start', [NovaReorderController::class, 'moveToStart']);
                Route::post('move-to-end', [NovaReorderController::class, 'moveToEnd']);
            });

        // The panel's video field, which writes a video straight to Azure and needs a link to
        // write it to. The API's own controller and use cases, reached from the panel's origin with
        // its session — the frontend's nginx forwards `/nova-vendor` and not `/api`. The role floor
        // is the API's; the panel's gate admits nobody below it anyway.
        Route::middleware(['nova:api', 'role:'.UserRole::Administrator->value])
            ->domain(config('nova.domain'))
            ->prefix('nova-vendor/kompaz/video-uploads')
            ->group(static function (): void {
                Route::post('/', [VideoUploadController::class, 'store']);
                Route::post('/{videoUpload}/complete', [VideoUploadController::class, 'complete']);
            });

        // The panel playing back an upload it is editing. Asked the way saving it is asked, not
        // the way a reader of the module is — see NovaVideoPreviewController.
        Route::middleware(['nova:api', 'role:'.UserRole::Administrator->value])
            ->domain(config('nova.domain'))
            ->prefix('nova-vendor/kompaz/video-previews')
            ->group(static function (): void {
                Route::get('/module-videos/{moduleVideo}', [NovaVideoPreviewController::class, 'moduleVideo'])
                    ->name('nova.video-preview.module-video');
                Route::get('/content-blocks/{contentBlock}', [NovaVideoPreviewController::class, 'contentBlock'])
                    ->name('nova.video-preview.content-block');
            });
    }

    /**
     * Who reaches the panel at all.
     *
     * An administrator of either kind, checked against the row on every request rather than against
     * anything the session remembers — a demotion takes effect on the operator's next click, not
     * when their session happens to expire.
     */
    protected function gate(): void
    {
        // An organization administrator manages their own tenant here, which is the same job the
        // API gives them; a platform administrator manages every tenant. Everything the panel then
        // shows either of them is scoped by {@see \App\Nova\Concerns\ScopesToOperator}, because
        // letting somebody in is only half of it — the other half is that the listings were
        // written when nobody but a platform administrator could reach them.
        Gate::define('viewNova', static function (User $user): bool {
            return $user->role->atLeast(UserRole::Administrator) && ! $user->isDeleted();
        });
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [];
    }
}
