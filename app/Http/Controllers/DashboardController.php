<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use App\Support\Shell\LocalShell;
use Illuminate\Http\JsonResponse;

/**
 * Home page extras (port of home.php): admins see the media01 TV and Movies
 * backup logs, in full up to the last MAX_LINES lines.
 */
class DashboardController extends Controller
{
    /** Guards the page against a runaway log; the panels scroll. */
    public const MAX_LINES = 5000;

    public function __invoke(MediaUser $user, LocalShell $shell): JsonResponse
    {
        if (! $user->isAdmin()) {
            return response()->json(['logs' => []]);
        }

        $logs = [];
        foreach (config('media.dashboard_logs') as $name => $title) {
            $file = rtrim(config('media.paths.log_dir'), '/')."/{$name}.log";
            if (! is_readable($file)) {
                $logs[] = ['name' => $name, 'title' => $title, 'modified' => null, 'lines' => []];

                continue;
            }

            $result = $shell->run(['tail', '-n', (string) self::MAX_LINES, $file], timeout: 10);
            $logs[] = [
                'name' => $name,
                'title' => $title,
                'modified' => @filemtime($file) ?: null,
                'lines' => array_values(array_filter(
                    preg_split('/\R/', mb_convert_encoding($result->output(), 'UTF-8', 'UTF-8')),
                    fn (string $line) => trim($line) !== '',
                )),
            ];
        }

        return response()->json(['logs' => $logs]);
    }
}
