<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Ops\DriveReport;
use App\Ops\LatestFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tools (admin): drives on both servers, latest files (ports of tools/*).
 */
class ToolsController extends Controller
{
    public function drives(Request $request, DriveReport $report): JsonResponse
    {
        $input = $request->validate(['server' => ['required', 'in:media02,media01']]);

        return response()->json($input['server'] === 'media01' ? $report->media01() : $report->media02());
    }

    public function latest(Request $request, LatestFiles $latest): JsonResponse
    {
        $input = $request->validate([
            'server' => ['required', 'in:media02,media01'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        set_time_limit(0);

        try {
            return response()->json($latest->page($input['server'], (int) ($input['page'] ?? 1), (bool) ($input['refresh'] ?? false), time()));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
