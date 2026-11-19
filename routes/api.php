<?php

use App\Http\Controllers\Acquire\IplayerController;
use App\Http\Controllers\Acquire\UploadController;
use App\Http\Controllers\Acquire\YoutubeController;
use App\Http\Controllers\Admin\FileOperationsController;
use App\Http\Controllers\Admin\JobsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\Ops\LiveTvController;
use App\Http\Controllers\Ops\LogController;
use App\Http\Controllers\Ops\ToolsController;
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

    Route::middleware('role:admin,ftp')->controller(UploadController::class)->group(function () {
        Route::get('/uploads', 'index');
        Route::post('/uploads', 'start');
        Route::post('/uploads/{id}/chunks', 'chunk');
        Route::post('/uploads/{id}/finish', 'finish');
        Route::delete('/uploads/{id}', 'cancel');
    });

    Route::middleware('role:admin')->prefix('iplayer')->controller(IplayerController::class)->group(function () {
        Route::get('/search', 'search');
        Route::post('/downloads', 'download');
        Route::get('/status', 'status');
    });

    Route::middleware('role:admin')->prefix('youtube')->controller(YoutubeController::class)->group(function () {
        Route::get('/info', 'info');
        Route::post('/downloads', 'download');
        Route::get('/status', 'status');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/live-tv/status', [LiveTvController::class, 'status']);
        Route::post('/live-tv/actions', [LiveTvController::class, 'action']);
        Route::get('/logs', [LogController::class, 'index']);
        Route::get('/logs/{key}', [LogController::class, 'show']);
        Route::get('/tools/drives', [ToolsController::class, 'drives']);
        Route::get('/tools/latest', [ToolsController::class, 'latest']);
    });

    Route::middleware('role:admin')->prefix('admin')->controller(FileOperationsController::class)->group(function () {
        Route::get('/servers', 'servers');
        Route::get('/media01/library', 'media01Library');
        Route::get('/folders', 'folders');
        Route::post('/folders', 'createFolder');
        Route::post('/items/move', 'move');
        Route::post('/items/delete', 'delete');
        Route::get('/copy-queue', 'copyQueue');
        Route::post('/copy-queue', 'queueCopy');
    });

    Route::middleware('role:admin')->prefix('admin')->controller(JobsController::class)->group(function () {
        Route::post('/extract', 'startExtract');
        Route::get('/extract/{id}', 'extractStatus');
        Route::get('/convert/presets', 'presets');
        Route::post('/convert', 'startConvert');
        Route::get('/convert/jobs', 'convertJobs');
    });
});
