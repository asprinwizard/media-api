<?php

namespace App\Http\Middleware;

use App\Auth\HtgroupRepository;
use App\Auth\MediaUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the user Apache authenticated into a MediaUser.
 *
 * Only REMOTE_USER is trusted: Apache sets it after checking the password.
 * PHP_AUTH_USER is deliberately ignored because mod_php fills it from the
 * client's Authorization header even where Apache did not check it.
 */
class ResolveHtUser
{
    public function __construct(private readonly HtgroupRepository $groups) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->devUser($request) ?? $this->apacheUser($request);

        if ($user === null) {
            return response()->json(['message' => 'Not logged in.'], 401);
        }

        if ($user->roles === []) {
            return response()->json(['message' => 'Your account has no access to this site.'], 403);
        }

        $request->attributes->set('mediaUser', $user);
        app()->instance(MediaUser::class, $user);

        return $next($request);
    }

    private function apacheUser(Request $request): ?MediaUser
    {
        // REDIRECT_REMOTE_USER: Apache's name for it after an internal redirect
        // (mod_rewrite to index.php). Both are server-set, never client-set.
        foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $key) {
            $username = $request->server($key);
            if (is_string($username) && $username !== '') {
                return new MediaUser($username, $this->groups->rolesFor($username));
            }
        }

        return null;
    }

    /**
     * Local development without Apache. All three conditions must hold.
     */
    private function devUser(Request $request): ?MediaUser
    {
        $dev = config('media.auth.dev');

        if (! $dev['enabled'] || ! app()->environment('local') || ! $dev['user']) {
            return null;
        }

        if (! in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return new MediaUser($dev['user'], array_values($dev['roles']));
    }
}
