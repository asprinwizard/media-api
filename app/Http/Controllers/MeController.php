<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use Illuminate\Http\JsonResponse;

/**
 * Who is logged in, their roles and the menu they may see.
 * The SPA builds its navigation from this, so role rules live only here.
 */
class MeController extends Controller
{
    public function __invoke(MediaUser $user): JsonResponse
    {
        $menu = collect(config('media.menu'))
            ->filter(fn (array $item) => $user->hasAnyRole($item['roles']))
            ->map(fn (array $item) => [
                'key' => $item['key'],
                'title' => $item['title'],
                'path' => $item['path'],
                'group' => $item['group'],
                'roles' => $item['roles'],
            ])
            ->values();

        $links = $user->isAdmin() ? array_filter(config('media.links')) : [];

        return response()->json([
            'user' => $user->username,
            'roles' => $user->roles,
            'siteTitle' => config('media.site_title'),
            'menu' => $menu,
            'links' => (object) $links,
        ]);
    }
}
