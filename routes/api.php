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
| The invitation secret is itself the credential presented to the anonymous
| token endpoint. Magic-link sign-in is not part of the public API; it belongs
| to Nova's browser routes in web.php.
|
| Refreshing and signing out are authenticated: the token being renewed or
| withdrawn is the one the request carries.
*/

Route::prefix('auth')->group(function (): void {
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
