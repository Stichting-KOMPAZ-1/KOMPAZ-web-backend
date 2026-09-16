<?php

declare(strict_types=1);

use App\Http\Controllers\Nova\NovaSignInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The admin panel's own sign-in
|--------------------------------------------------------------------------
|
| Nova's built-in login form asks for a password, and this application has
| none. These three routes replace it with the same emailed link everybody
| else signs in with, pointed at the panel instead of at the frontend.
|
| Rate limited like the API's equivalent, and for the same reason: the page
| sends email, and it is reachable by anybody who finds the URL.
*/

Route::middleware('web')->group(function (): void {
    Route::get('/beheer/inloggen', [NovaSignInController::class, 'show'])->name('nova.sign-in');

    Route::post('/beheer/inloggen', [NovaSignInController::class, 'send'])
        ->middleware('throttle:magic-link')
        ->name('nova.sign-in.send');

    Route::get('/beheer/sessie', [NovaSignInController::class, 'claim'])
        ->middleware('throttle:sign-in')
        ->name('nova.sign-in.claim');

    Route::post('/beheer/afmelden', [NovaSignInController::class, 'signOut'])->name('nova.sign-out');

    Route::get('/', fn () => redirect()->route('nova.sign-in'));
});
