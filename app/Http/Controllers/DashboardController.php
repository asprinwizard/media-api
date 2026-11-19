<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use App\Support\Shell\LocalShell;
use Illuminate\Http\JsonResponse;

/**
 * Home page extras (port of home.php): admins see the last 20 lines of the
 * media01 TV and Movies copy logs.
 */
class DashboardController extends Controller
{
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

            $result = $shell->run(['tail', '-n', '20', $file], timeout: 10);
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
