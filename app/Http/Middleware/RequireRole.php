<?php

namespace App\Http\Middleware;

use App\Auth\MediaUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,ftp')
 * The user needs at least one of the listed roles.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('mediaUser');

        if (! $user instanceof MediaUser) {
            return response()->json(['message' => 'Not logged in.'], 401);
        }

        if (! $user->hasAnyRole($roles)) {
            return response()->json(['message' => 'You don\'t have access to this.'], 403);
        }

        return $next($request);
    }
}
