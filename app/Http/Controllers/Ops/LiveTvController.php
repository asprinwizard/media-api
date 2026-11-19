<?php

namespace App\Http\Controllers\Ops;

use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use App\Ops\LiveTv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Live TV (admin): status and power actions (ports of live-tv/xhr_*).
 * The page does the waiting: it polls status while the box boots.
 */
class LiveTvController extends Controller
{
    public function status(LiveTv $tv): JsonResponse
    {
        return response()->json([
            'online' => $tv->online(),
            'canWake' => $tv->canWake(),
            'hls' => config('media.live_tv.hls'),
            'bootTimeout' => config('media.live_tv.boot_timeout'),
        ]);
    }

    public function action(Request $request, MediaUser $user, LiveTv $tv): JsonResponse
    {
        $input = $request->validate(['action' => ['required', 'in:wake,shutdown,reboot,sleep']]);

        try {
            $input['action'] === 'wake' ? $tv->wake() : $tv->power($input['action']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        Log::info('live-tv.action', ['user' => $user->username, 'action' => $input['action']]);

        return response()->json(['ok' => true]);
    }
}
