<?php

declare(strict_types=1);

use App\Http\Controllers\Nova\NovaOrganizationLogoController;
use App\Http\Controllers\Nova\NovaSignInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The admin panel's own sign-in
|--------------------------------------------------------------------------
|
| Nova's built-in login form asks for a password, and this application has
| none. These routes replace it with an emailed link dedicated to the panel.
|
| Rate limited like the API's equivalent, and for the same reason: the page
| sends email, and it is reachable by anybody who finds the URL.
*/

Route::middleware('web')->group(function (): void {
    Route::get('/beheer/inloggen', [NovaSignInController::class, 'show'])->name('nova.sign-in');

    Route::post('/beheer/inloggen', [NovaSignInController::class, 'send'])
        ->middleware('throttle:magic-link')
        ->name('nova.sign-in.send');

    /*
    | Where every emailed link lands: the panel's own, an invitation, and one somebody asked for
    | themselves. Throttled because it spends a secret somebody can arrive with, over and over,
    | without signing in first.
    */
    Route::get('/beheer/sessie', [NovaSignInController::class, 'claim'])
        ->middleware('throttle:sign-in')
        ->name('nova.sign-in.claim');

    Route::post('/beheer/afmelden', [NovaSignInController::class, 'signOut'])->name('nova.sign-out');

    /*
    | The panel's own read of a logo, because an <img> on a Nova page carries the session cookie
    | and not a bearer token, which puts the API's logo endpoint out of reach. Guarded by the same
    | two things every Nova page is: a session, and the `viewNova` gate.
    */
    Route::get('/beheer/organisaties/{organization}/logo', [NovaOrganizationLogoController::class, 'show'])
        ->middleware(['auth:web', 'can:viewNova'])
        ->name('nova.organization-logo');

    Route::get('/', fn () => redirect()->route('nova.sign-in'));
});
