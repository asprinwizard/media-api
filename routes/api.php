<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Every route in the first group sits behind Apache Basic auth. ResolveHtUser
| turns the Apache-authenticated user into a MediaUser with roles from
| .htgroup, and RequireSameOrigin refuses cross-site state-changing requests.
|
| Paths here have no /api prefix: Apache serves the app under "Alias /api",
| so "/me" is https://<host>/api/me.
*/

Route::middleware(['media.user', 'same-origin'])->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/dashboard', DashboardController::class);

    Route::get('/library', LibraryController::class);
    Route::get('/search', SearchController::class);

    Route::prefix('files')->controller(FileController::class)->group(function () {
        Route::get('/info', 'info');
        Route::get('/play', 'play');
        Route::get('/download', 'download');
        Route::get('/cast', 'cast');
        Route::post('/link', 'logLink');
    });
});

/*
| Unauthenticated, signed links for Chromecast: /api/public/* in the browser.
| Apache lets these through without Basic auth (see deploy/ vhost); the
| `signed` middleware checks signature and expiry.
*/
Route::middleware('signed')->prefix('public')->name('public.')->controller(PublicMediaController::class)->group(function () {
    Route::get('/stream', 'stream')->name('stream');
    Route::get('/download', 'download')->name('download');
});
