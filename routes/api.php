<?php

use App\Http\Controllers\Admin\FileOperationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MeController;
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

    Route::middleware('role:admin')->prefix('admin')->controller(FileOperationsController::class)->group(function () {
        Route::get('/servers', 'servers');
        Route::get('/folders', 'folders');
        Route::post('/folders', 'createFolder');
        Route::post('/items/move', 'move');
        Route::post('/items/delete', 'delete');
        Route::get('/copy-queue', 'copyQueue');
        Route::post('/copy-queue', 'queueCopy');
    });
});
