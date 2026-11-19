<?php

namespace App\Library;

use Illuminate\Support\Facades\Log;

/**
 * Appends to the activity logs the Logs page shows (port of media-web's
 * Log::logFileAction / logFileSearch).
 *
 * Line format is unchanged: "Y-m-d H:i:s : <action> : <user> : <detail>".
 * Unlike the old site, file paths are always the real absolute path and the
 * time is always the server's (the old site mixed both, see plan §5.2).
 */
class ActivityLogger
{
    public const FILE_LINK = 'file link';

    public const DOWNLOAD = 'download';

    public const PLAY = 'play';

    public const STREAM = 'stream';

    public function fileAction(string $action, string $user, string $absolutePath): void
    {
        $this->append('file_actions.log', sprintf("%s : %s : %s : %s\n", $this->now(), $action, $user, $absolutePath));
    }

    public function search(string $user, string $keyword): void
    {
        $this->append('file_searches.log', sprintf("%s : %s : %s\n", $this->now(), $user, $keyword));
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    private function append(string $file, string $line): void
    {
        $path = rtrim(config('media.paths.public'), '/').'/logs/'.$file;

        // Never break a download or search because a log can't be written.
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            Log::warning('Could not write activity log', ['file' => $path]);
        }
    }
}
