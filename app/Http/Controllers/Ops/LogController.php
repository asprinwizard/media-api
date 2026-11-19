<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Ops\LogViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logs (admin): the configured logs, tailed or in full (ports of logs/xhr-*).
 */
class LogController extends Controller
{
    public function index(LogViewer $logs): JsonResponse
    {
        return response()->json(['logs' => $logs->list()]);
    }

    public function show(Request $request, string $key, LogViewer $logs): JsonResponse
    {
        $input = $request->validate(['lines' => ['nullable', 'integer', 'min:1', 'max:5000']]);

        try {
            return response()->json($logs->read($key, isset($input['lines']) ? (int) $input['lines'] : null));
        } catch (\OutOfBoundsException) {
            return response()->json(['message' => 'There\'s no log by that name.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }
}
