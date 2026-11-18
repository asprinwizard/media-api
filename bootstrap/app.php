<?php

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireSameOrigin;
use App\Http\Middleware\ResolveHtUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // No /api prefix here: Apache serves this app under "Alias /api", so
        // Laravel already sees /api as its base path (route "/me" = /api/me).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No sessions, cookies or CSRF tokens: auth is Apache Basic auth, and
        // cross-site writes are refused by RequireSameOrigin instead.
        $middleware->alias([
            'media.user' => ResolveHtUser::class,
            'role' => RequireRole::class,
            'same-origin' => RequireSameOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only app: every error is JSON.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
    })->create();
