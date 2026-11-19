<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use App\Library\ActivityLogger;
use App\Library\FileInspector;
use App\Library\FileSender;
use App\Library\FileType;
use App\Library\LibraryService;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * One library file: info, play, download, cast.
 * Ports of xhr_file_info.php, xhr_play_file.php, download.php and cast.php.
 */
class FileController extends Controller
{
    public function __construct(
        private readonly MediaPathResolver $paths,
        private readonly FileInspector $inspector,
        private readonly ActivityLogger $activity,
    ) {}

    public function info(Request $request, MediaUser $user): JsonResponse
    {
        $file = $this->file($request, $user);
        $absolute = $file->absolute();
        $info = $this->inspector->analyse($absolute);

        $general = [
            'name' => $file->name(),
            'size' => $this->inspector->size($absolute),
            'format' => $info['fileformat'] ?? null,
            'mime' => $info['mime_type'] ?? $this->inspector->mimeType($absolute),
            'duration' => $info['playtime_string'] ?? null,
        ];

        if (! $user->isAdmin()) {
            return response()->json(['general' => $general]);
        }

        return response()->json([
            'general' => $general,
            'admin' => [
                'path' => $absolute,
                'created' => @filectime($absolute) ?: null,
                'modified' => @filemtime($absolute) ?: null,
                'video' => $this->scalars($info['video'] ?? []),
                'audio' => $this->scalars($info['audio'] ?? []),
                'comments' => array_map(
                    fn ($values) => implode(', ', array_map('strval', (array) FileInspector::sanitise($values))),
                    $info['comments'] ?? [],
                ),
                'raw' => FileInspector::sanitise($info),
            ],
        ], options: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function play(Request $request, MediaUser $user, LibraryService $library): JsonResponse
    {
        $file = $this->file($request, $user);
        abort_unless(FileType::fromFileName($file->name())->isPlayable(), 422, 'This file can\'t be played.');

        $mime = $this->inspector->mimeType($file->absolute());
        $this->activity->fileAction(ActivityLogger::PLAY, $user->username, $file->absolute());

        return response()->json([
            'path' => $file->relative,
            'name' => $file->name(),
            'src' => self::mediaUrl($file),
            'mime' => $mime,
            'streamable' => $this->inspector->isStreamable($mime),
            'siblings' => $library->playableSiblings($file),
        ]);
    }

    public function download(Request $request, MediaUser $user, FileSender $sender): Response
    {
        $file = $this->file($request, $user);
        $this->activity->fileAction(ActivityLogger::DOWNLOAD, $user->username, $file->absolute());

        return $sender->send($file->absolute(), $this->inspector->mimeType($file->absolute()), 'attachment');
    }

    /**
     * Links a Chromecast can open without Basic auth. Files browsers can't
     * stream (DivX AVI, WMV) get a download link instead, as on the old site.
     */
    public function cast(Request $request, MediaUser $user): JsonResponse
    {
        $file = $this->file($request, $user);
        $mime = $this->inspector->mimeType($file->absolute());
        $streamable = $this->inspector->isStreamable($mime);
        $expires = now()->addHours(config('media.signed_url_ttl_hours'));

        return response()->json([
            'name' => $file->name(),
            'mime' => $mime,
            'streamable' => $streamable,
            'url' => URL::temporarySignedRoute(
                $streamable ? 'public.stream' : 'public.download',
                $expires,
                ['path' => $file->relative],
            ),
            'expires' => $expires->getTimestamp(),
        ]);
    }

    /**
     * Logs a click on a file name (the browser then opens /media/Video/...).
     */
    public function logLink(Request $request, MediaUser $user): JsonResponse
    {
        $file = $this->file($request, $user);
        $this->activity->fileAction(ActivityLogger::FILE_LINK, $user->username, $file->absolute());

        return response()->json(['logged' => true]);
    }

    /**
     * Direct Apache URL for a library file (the /media alias), each segment
     * percent-encoded so "#", "%" and "?" in names survive.
     */
    public static function mediaUrl(MediaPath $file): string
    {
        return '/media/Video/'.implode('/', array_map('rawurlencode', $file->segments()));
    }

    private function file(Request $request, MediaUser $user): MediaPath
    {
        $input = $request->validate(['path' => ['required', 'string', 'max:4096']]);
        $file = $this->paths->library($input['path'], $user);
        abort_unless(is_file($file->absolute()), 404, 'File not found.');

        return $file;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function scalars(array $section): array
    {
        return array_filter(
            array_map(fn ($v) => is_array($v) ? null : FileInspector::sanitise($v), $section),
            fn ($v) => $v !== null && $v !== '',
        );
    }
}
