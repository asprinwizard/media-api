<?php

namespace App\Http\Controllers\Acquire;

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
 * Upload page (admin, ftp): one file per POST into the upload folder
 * (port of ftp/xhr_upload_file.php and xhr_get_folder_contents.php).
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
            'maxBytes' => self::maxUploadBytes(),
            'items' => array_map(fn (DirectoryEntry $e) => [
                'name' => $e->name,
                'type' => $e->type->value,
                'size' => $e->size,
                'mtime' => $e->mtime,
            ], SortKey::sort($entries, 'date', 'desc')),
        ]);
    }

    public function store(Request $request, MediaUser $user): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            // PHP drops the whole body when it's over post_max_size.
            return response()->json(['message' => 'No file arrived. It may be bigger than the server allows ('.self::human(self::maxUploadBytes()).').'], 422);
        }
        if (! $file->isValid()) {
            $message = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is bigger than the server allows ('.self::human(self::maxUploadBytes()).').'
                : 'The upload didn\'t complete. Please try again.';

            return response()->json(['message' => $message], 422);
        }

        $name = trim(basename(str_replace('\\', '/', $file->getClientOriginalName())));
        if ($name === '' || str_starts_with($name, '.')) {
            return response()->json(['message' => 'That file name can\'t be used.'], 422);
        }
        $target = $this->paths->upload($name);
        if (file_exists($target->absolute())) {
            return response()->json(['message' => "\"{$name}\" is already in the upload folder. Rename it and try again."], 409);
        }

        // Read the size before moving (the temp file goes), and don't let a
        // failed stat (files over 2 GB on 32-bit PHP) stop the upload.
        $size = rescue(fn () => $file->getSize(), null, false);

        try {
            $file->move(dirname($target->absolute()), $name);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'The file couldn\'t be saved on the server.'], 500);
        }
        @chmod($target->absolute(), 0664);

        Log::info('upload', ['user' => $user->username, 'file' => $name, 'size' => $size]);

        return response()->json(['name' => $name], 201);
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

    private static function human(?float $bytes): string
    {
        if ($bytes === null) {
            return 'no limit';
        }

        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }
}
