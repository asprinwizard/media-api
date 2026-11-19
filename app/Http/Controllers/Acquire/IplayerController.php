<?php

namespace App\Http\Controllers\Acquire;

use App\Acquire\Iplayer\IplayerService;
use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BBC iPlayer (admin): search, queue downloads, status
 * (ports of iplayer/xhr_search, xhr_download, xhr_status).
 */
class IplayerController extends Controller
{
    public function __construct(private readonly IplayerService $iplayer) {}

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);
        set_time_limit(0);

        try {
            return response()->json(['results' => $this->iplayer->search(trim($input['q']))]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'The search failed: '.mb_substr($e->getMessage(), 0, 200)], 502);
        }
    }

    /**
     * Queue PIDs: either several from the search results, or one typed in
     * (a PID or an iPlayer link), optionally with --pid-recursive.
     */
    public function download(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'pids' => ['required', 'array', 'min:1', 'max:100'],
            'pids.*' => ['required', 'string', 'max:300'],
            'force' => ['boolean'],
            'sdCopy' => ['boolean'],
            'recursive' => ['boolean'],
        ]);

        $pids = [];
        foreach ($input['pids'] as $raw) {
            $pid = IplayerService::normalisePid($raw);
            if ($pid === null) {
                return response()->json(['message' => "\"{$raw}\" isn't a PID or an iPlayer link.", 'errors' => ['pids' => ['Invalid PID.']]], 422);
            }
            $pids[$pid] = $pid;
        }

        try {
            $lines = $this->iplayer->queue(array_values($pids), $input['force'] ?? false, $input['sdCopy'] ?? false, $input['recursive'] ?? false);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        Log::info('iplayer.download', ['user' => $user->username, 'pids' => array_values($pids), 'lines' => $lines]);

        return response()->json(['queued' => count($pids), 'queue' => $this->iplayer->queueContents()], 202);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->iplayer->status(time()));
    }
}
