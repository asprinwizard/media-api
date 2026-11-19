<?php

namespace App\Http\Controllers\Acquire;

use App\Acquire\Youtube\YoutubeService;
use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * YouTube (admin): look up a link, download, status
 * (ports of youtube/xhr_get_info, xhr_download, xhr_status).
 */
class YoutubeController extends Controller
{
    public function __construct(private readonly YoutubeService $youtube) {}

    public function info(Request $request): JsonResponse
    {
        $input = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        $link = YoutubeService::classify($input['url']);

        if ($link['kind'] !== 'video') {
            return response()->json($link + ['title' => null, 'formats' => null]);
        }

        set_time_limit(0);
        try {
            return response()->json($link + $this->youtube->info($link['url']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function download(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
            'video' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,40}$/'],
            'audio' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,40}$/'],
        ]);
        $link = YoutubeService::classify($input['url']);
        abort_if($link['kind'] === 'invalid', 422, 'That isn\'t a YouTube video or playlist link.');

        // One at a time, as before (the old page disabled Find while busy).
        abort_if($this->youtube->status(time())['active'], 409, 'A download is already running. Wait for it to finish.');

        $playlist = $link['kind'] === 'playlist';
        $this->youtube->download($link['url'], $playlist ? null : ($input['video'] ?? null), $playlist ? null : ($input['audio'] ?? null), $playlist);
        Log::info('youtube.download', ['user' => $user->username, 'url' => $link['url'], 'video' => $input['video'] ?? null, 'audio' => $input['audio'] ?? null]);

        return response()->json(['started' => true, 'kind' => $link['kind']], 202);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->youtube->status(time()));
    }
}
