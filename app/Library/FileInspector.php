<?php

namespace App\Library;

use App\Support\Shell\LocalShell;

/**
 * Size, type and media details of one file.
 */
class FileInspector
{
    public function __construct(private readonly LocalShell $shell) {}

    public function mimeType(string $absolutePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $absolutePath) : false;

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Bytes as a float. On 32-bit PHP filesize() fails above 2 GB, so ask
     * `stat` instead (as media-web's File::getFileSize did).
     */
    public function size(string $absolutePath): ?float
    {
        if (PHP_INT_SIZE >= 8) {
            $size = @filesize($absolutePath);

            return $size === false ? null : (float) $size;
        }

        $result = $this->shell->run(['stat', '-L', '-c', '%s', $absolutePath], timeout: 30);

        return $result->successful() ? (float) trim($result->output()) : null;
    }

    public function isStreamable(string $mimeType): bool
    {
        return ! in_array($mimeType, config('media.non_streamable_mime'), true);
    }

    /**
     * getID3 analysis, reduced to JSON-safe values.
     *
     * @return array<string, mixed>
     */
    public function analyse(string $absolutePath): array
    {
        try {
            $getId3 = new \getID3;
            $info = $getId3->analyze($absolutePath);
            \getid3_lib::CopyTagsToComments($info);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return $info;
    }

    /**
     * Make getID3 output safe to json_encode: binary blobs (cover art, raw
     * atoms) become a placeholder, long strings and arrays are trimmed.
     */
    public static function sanitise(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth >= 6) {
                return '[…]';
            }
            $out = [];
            foreach (array_slice($value, 0, 200, true) as $key => $item) {
                $out[$key] = self::sanitise($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0e-\x1f]/', $value)) {
                return '[binary, '.strlen($value).' bytes]';
            }

            return mb_strlen($value) > 500 ? mb_substr($value, 0, 500).'…' : $value;
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }

        return is_scalar($value) || $value === null ? $value : '['.get_debug_type($value).']';
    }
}
