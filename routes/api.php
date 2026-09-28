<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ELearningController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Both anonymous endpoints are credentials in their own right: the invitation
| secret presented to the token endpoint, and the email address presented to
| the link endpoint. Nova's own link flow is not here — it opens a browser
| session rather than issuing a token, and lives in web.php.
|
| Asking for a link and spending one are throttled separately, because only
| the first sends email.
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

        // Archiving is the reversible counterpart to the delete above, so it is reserved to the
        // same seniority. Restoring is the same operation undone and answers on the same address.
        Route::post('/{organization}/archive', [OrganizationController::class, 'archive'])
            ->middleware('role:'.UserRole::PlatformAdministrator->value);

        Route::delete('/{organization}/archive', [OrganizationController::class, 'unarchive'])
            ->middleware('role:'.UserRole::PlatformAdministrator->value);

        Route::get('/{organization}/logo', [OrganizationLogoController::class, 'show']);

        Route::put('/{organization}/logo', [OrganizationLogoController::class, 'update'])
            ->middleware('role:'.UserRole::Administrator->value);

        Route::delete('/{organization}/logo', [OrganizationLogoController::class, 'destroy'])
            ->middleware('role:'.UserRole::Administrator->value);
    });

    /*
    |----------------------------------------------------------------------
    | Content
    |----------------------------------------------------------------------
    |
    | Read-only, and deliberately so: modules and courses are written in the
    | operator's panel and nowhere else. A role floor would be wrong on all
    | of these — the person a module is written for is a Zorgprofessional,
    | the most junior role there is.
    |
    | The tenant question is not the one the routes above ask. A module
    | carries no organization, so ModuleAccess asks whether it has been
    | switched on for the caller's, and answers 404 rather than 403 when it
    | has not: which modules the platform has written is not something one
    | organization should be able to enumerate through the other's refusals.
    |
    | Files are nested under what they belong to, never addressed alone. The
    | parent is where permission comes from, and a bare file identifier
    | would be a way to ask for bytes without naming what they are part of.
    */

    Route::prefix('modules')->group(function (): void {
        Route::get('/', [ModuleController::class, 'index']);
        Route::get('/{module}', [ModuleController::class, 'show']);
        Route::get('/{module}/image', [ModuleController::class, 'image']);
        Route::get('/{module}/videos/{video}/file', [ModuleController::class, 'videoFile']);
    });

    Route::prefix('e-learnings')->group(function (): void {
        Route::get('/{eLearning}', [ELearningController::class, 'show']);
        Route::get('/{eLearning}/image', [ELearningController::class, 'image']);
        Route::get('/{eLearning}/steps/{step}', [ELearningController::class, 'step']);
        Route::get('/{eLearning}/steps/{step}/blocks/{block}/file', [ELearningController::class, 'blockFile']);
    });
});
