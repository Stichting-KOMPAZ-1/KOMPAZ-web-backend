<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Two anonymous entry points, and only these two. Each is anonymous by
| necessity: asking for a link is what somebody who cannot sign in does, and
| the secret from the link is itself the credential being presented.
|
| They get two rate-limit budgets rather than one. Asking for a link is the
| expensive half — it sends email — and it must not be able to exhaust the
| allowance that the resulting click needs to spend.
|
| Refreshing and signing out are authenticated: the token being renewed or
| withdrawn is the one the request carries.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/magic-link', [AuthController::class, 'requestMagicLink'])
        ->middleware('throttle:magic-link');

    Route::post('/tokens', [AuthController::class, 'redeem'])
        ->middleware('throttle:sign-in');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/tokens/refresh', [AuthController::class, 'refresh']);
        Route::delete('/tokens/current', [AuthController::class, 'revoke']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Everything else
|--------------------------------------------------------------------------
|
| A role on a route is a floor on seniority and never a tenant check; every
| action behind these still asks OrganizationAccess whose data this is.
|
| Sanctum resolves the user row on every request, so a deletion or a demotion
| takes effect on the caller's very next call with no comparison to make.
*/

Route::middleware('auth:sanctum')->group(function (): void {

    Route::prefix('users')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::put('/me', [UserController::class, 'updateOwnProfile']);

        Route::get('/{user}', [UserController::class, 'show']);

        Route::post('/invitations', [UserController::class, 'invite'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::post('/{user}/invitations', [UserController::class, 'resendInvitation'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::post('/{user}/restore', [UserController::class, 'restore'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::put('/{user}', [UserController::class, 'update'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::delete('/{user}', [UserController::class, 'destroy'])
            ->middleware('role:'.UserRole::Administrator->value);
    });

    Route::prefix('organizations')->group(function (): void {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::get('/{organization}', [OrganizationController::class, 'show']);

        Route::post('/', [OrganizationController::class, 'store'])
            ->middleware('role:'.UserRole::PlatformAdministrator->value);

        Route::put('/{organization}', [OrganizationController::class, 'update'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::delete('/{organization}', [OrganizationController::class, 'destroy'])
            ->middleware('role:'.UserRole::PlatformAdministrator->value);

        Route::get('/{organization}/logo', [OrganizationLogoController::class, 'show']);

        Route::put('/{organization}/logo', [OrganizationLogoController::class, 'update'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::delete('/{organization}/logo', [OrganizationLogoController::class, 'destroy'])
            ->middleware('role:'.UserRole::Administrator->value);
    });
});
