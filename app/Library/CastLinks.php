<?php

namespace App\Library;

use App\Support\Paths\MediaPath;
use Illuminate\Support\Facades\Log;

/**
 * Temporary, unauthenticated links for Chromecast.
 *
 * A Chromecast can't send Basic-auth credentials, so each link is a folder
 * with a random 128-bit name holding one symlink to the file:
 *
 *     <cast path>/<32 hex>/<file name>  ->  /home/share/Video/…/<file name>
 *
 * Apache serves it at /shared/<32 hex>/<file name> without login (directory
 * listing off), with Range support and no PHP involved. Folders older than
 * the TTL are removed whenever a new link is made.
 */
class CastLinks
{
    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private readonly string $root,
        private readonly string $urlPrefix,
        private readonly int $ttlHours,
    ) {}

    /**
     * @return array{url: string, expires: int}
     */
    public function create(MediaPath $file, bool $download = false): array
    {
        $this->cleanup();

        if (! is_dir($this->root) && ! @mkdir($this->root, 0711, true) && ! is_dir($this->root)) {
            throw new \RuntimeException("Can't create the cast folder {$this->root}.");
        }

        $token = bin2hex(random_bytes(16));
        $dir = "{$this->root}/{$token}";
        if (! @mkdir($dir, 0755) || ! @symlink($file->absolute(), "{$dir}/{$file->name()}")) {
            throw new \RuntimeException("Can't create a cast link in {$this->root}.");
        }

        return [
            'url' => rtrim($this->urlPrefix, '/')."/{$token}/".rawurlencode($file->name()).($download ? '?download' : ''),
            'expires' => now()->addHours($this->ttlHours)->getTimestamp(),
        ];
    }

    /**
     * Remove link folders older than the TTL. Only touches folders named
     * like a token, and only symlinks inside them, never real files.
     */
    public function cleanup(): int
    {
        if (! is_dir($this->root)) {
            return 0;
        }

        $cutoff = time() - $this->ttlHours * 3600;
        $removed = 0;

        foreach (scandir($this->root) ?: [] as $name) {
            $dir = "{$this->root}/{$name}";
            if (! preg_match(self::TOKEN_PATTERN, $name) || is_link($dir) || ! is_dir($dir)) {
                continue;
            }
            if ((lstat($dir)['mtime'] ?? PHP_INT_MAX) >= $cutoff) {
                continue;
            }

            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_link("{$dir}/{$entry}")) {
                    @unlink("{$dir}/{$entry}");
                }
            }
            if (@rmdir($dir)) {
                $removed++;
            } else {
                Log::warning('Could not remove expired cast link folder', ['dir' => $dir]);
            }
        }

        return $removed;
    }
}
