<?php

use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
| Every route here sits behind Apache Basic auth. ResolveHtUser turns the
| Apache-authenticated user into a MediaUser with roles from .htgroup, and
| RequireSameOrigin refuses cross-site state-changing requests.
|
| Paths here have no /api prefix: Apache serves the app under "Alias /api",
| so "/me" is https://<host>/api/me.
|
| Unauthenticated, signed routes for Chromecast (/public/* here, /api/public/*
| in the browser) are added in Phase 2 outside this group.
*/

Route::middleware(['media.user', 'same-origin'])->group(function () {
    Route::get('/me', MeController::class);
});
