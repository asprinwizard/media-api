<?php

namespace App\Http\Controllers;

use App\Library\ActivityLogger;
use App\Library\FileInspector;
use App\Library\FileSender;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unauthenticated, signed links for Chromecast (port of modules/api/*).
 *
 * The `signed` middleware has already checked the signature and expiry, and a
 * signature is only ever issued to a logged-in user allowed to see the file,
 * so restricted folders are not re-checked here. Path checks still apply.
 */
class PublicMediaController extends Controller
{
    public function __construct(
        private readonly MediaPathResolver $paths,
        private readonly FileInspector $inspector,
        private readonly FileSender $sender,
        private readonly ActivityLogger $activity,
    ) {}

    public function stream(Request $request): Response
    {
        $file = $this->file($request);
        $mime = $this->inspector->mimeType($file->absolute());
        $this->activity->fileAction(ActivityLogger::STREAM, 'public', $file->absolute());

        // The old stream endpoint always said video/mp4, which Chromecast
        // relies on; keep that for video, but let audio keep its own type.
        $contentType = str_starts_with($mime, 'audio/') ? $mime : 'video/mp4';

        return $this->sender->send($file->absolute(), $contentType, 'inline');
    }

    public function download(Request $request): Response
    {
        $file = $this->file($request);
        $this->activity->fileAction(ActivityLogger::DOWNLOAD, 'public', $file->absolute());

        return $this->sender->send($file->absolute(), $this->inspector->mimeType($file->absolute()), 'attachment');
    }

    private function file(Request $request): MediaPath
    {
        $file = $this->paths->librarySigned((string) $request->query('path', ''));
        abort_unless(is_file($file->absolute()), 404, 'File not found.');

        return $file;
    }
}
