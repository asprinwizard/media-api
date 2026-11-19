<?php

namespace App\Http\Controllers\Acquire;

use App\Acquire\Upload\ChunkedUploads;
use App\Acquire\Upload\UploadRefused;
use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use App\Library\DirectoryEntry;
use App\Library\DirectoryReader;
use App\Library\SortKey;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Upload page (admin, ftp): files arrive in chunks (see ChunkedUploads)
 * and land in the upload folder (port of ftp/xhr_upload_file.php and
 * xhr_get_folder_contents.php).
 */
class UploadController extends Controller
{
    public function __construct(private readonly MediaPathResolver $paths) {}

    /**
     * The upload folder's contents (newest first) and the size limit.
     */
    public function index(DirectoryReader $reader): JsonResponse
    {
        $folder = $this->paths->upload();
        $entries = is_dir($folder->absolute()) ? $reader->read($folder->absolute()) : [];

        return response()->json([
            'chunkBytes' => self::chunkBytes(),
            'items' => array_map(fn (DirectoryEntry $e) => [
                'name' => $e->name,
                'type' => $e->type->value,
                'size' => $e->size,
                'mtime' => $e->mtime,
            ], SortKey::sort($entries, 'date', 'desc')),
        ]);
    }

    /**
     * Start a chunked upload: {name, size} → {id, name, chunkBytes}.
     */
    public function start(Request $request, MediaUser $user, ChunkedUploads $uploads): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'numeric', 'min:0', 'max:1099511627776'],
        ]);

        return $this->attempt(function () use ($input, $user, $uploads) {
            $started = $uploads->start($input['name'], (float) $input['size'], $user->username);

            return response()->json($started + ['chunkBytes' => self::chunkBytes()], 201);
        });
    }

    /**
     * One chunk (multipart field "chunk"), number ?index= (0-based).
     */
    public function chunk(Request $request, string $id, MediaUser $user, ChunkedUploads $uploads): JsonResponse
    {
        $index = filter_var($request->query('index'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $file = $request->file('chunk');
        if ($index === false) {
            return response()->json(['message' => 'Missing chunk number.'], 422);
        }
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return response()->json(['message' => 'That part of the file didn\'t arrive. It will be retried.'], 422);
        }

        return $this->attempt(fn () => response()->json(
            $uploads->append($id, $index, $file->getPathname(), $user->username),
        ));
    }

    public function finish(string $id, MediaUser $user, ChunkedUploads $uploads): JsonResponse
    {
        set_time_limit(0);

        return $this->attempt(function () use ($id, $user, $uploads) {
            $done = $uploads->finish($id, $user->username);
            Log::info('upload', ['user' => $user->username, 'file' => $done['name'], 'size' => $done['size']]);

            return response()->json(['name' => $done['name']], 201);
        });
    }

    public function cancel(string $id, MediaUser $user, ChunkedUploads $uploads): JsonResponse
    {
        return $this->attempt(function () use ($id, $user, $uploads) {
            $uploads->cancel($id, $user->username);

            return response()->json(null, 204);
        });
    }

    /**
     * Chunk size: the configured size, kept well inside PHP's own limits.
     */
    public static function chunkBytes(): int
    {
        $configured = (int) config('media.upload.chunk_bytes');
        $php = self::maxUploadBytes();

        return (int) max(256 * 1024, $php === null ? $configured : min($configured, floor($php * 0.9)));
    }

    /**
     * @param  callable(): JsonResponse  $work
     */
    private function attempt(callable $work): JsonResponse
    {
        try {
            return $work();
        } catch (UploadRefused $e) {
            return response()->json(['message' => $e->getMessage()] + $e->extra, $e->status);
        }
    }

    /** The smaller of upload_max_filesize and post_max_size, in bytes (null = unlimited). */
    public static function maxUploadBytes(): ?float
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn ($v) => $v !== null && $v > 0);

        return $limits === [] ? null : min($limits);
    }

    public static function iniBytes(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
