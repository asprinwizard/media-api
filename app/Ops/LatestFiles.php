<?php

namespace App\Ops;

use App\Library\FileType;
use App\Support\Shell\LocalShell;
use App\Support\Shell\RemoteShell;

/**
 * Tools › Latest files (port of tools/latest-files): the newest files in
 * the library on either server. Scanning the whole library is slow (and
 * spins up every drive), so one scan keeps the newest N files for a while
 * and pages are served from that.
 */
class LatestFiles
{
    /** $1 root, $2 how many: newest first, "mtime \t size \t relative path". Hidden files and folders are skipped. */
    public const SCRIPT = 'find -L "$1" -mindepth 1 -name ".*" -prune -o -type f -printf "%T@\t%s\t%P\n" 2>/dev/null | sort -rn | head -n "$2"';

    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly LocalShell $local,
        private readonly RemoteShell $media01,
        private readonly string $media02Root,
        private readonly string $media01Root,
        private readonly string $cacheDir,
        private readonly int $keep,
        private readonly int $cacheMinutes,
        private readonly int $timeout,
    ) {}

    /**
     * @return array{server: string, scanned: int, items: list<array<string, mixed>>, page: int, more: bool, total: int}
     */
    public function page(string $server, int $page, bool $refresh, int $now): array
    {
        $cache = "{$this->cacheDir}/latest-{$server}.tsv";
        $fresh = is_file($cache) && $now - (int) filemtime($cache) < $this->cacheMinutes * 60;
        if ($refresh || ! $fresh) {
            $this->scan($server, $cache);
        }

        $lines = array_values(array_filter(file($cache, FILE_IGNORE_NEW_LINES) ?: [], 'strlen'));
        $slice = array_slice($lines, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'server' => $server,
            'scanned' => (int) filemtime($cache),
            'items' => array_map(self::item(...), $slice),
            'page' => $page,
            'more' => count($lines) > $page * self::PAGE_SIZE,
            'total' => count($lines),
        ];
    }

    /**
     * @return array{path: string, name: string, folder: string, size: float, mtime: int, type: string}
     */
    public static function item(string $line): array
    {
        [$mtime, $size, $path] = array_pad(explode("\t", $line, 3), 3, '');
        $name = basename($path);

        return [
            'path' => $path,
            'name' => $name,
            'folder' => dirname($path) === '.' ? '' : dirname($path),
            'size' => (float) $size,
            'mtime' => (int) $mtime,
            'type' => FileType::fromFileName($name)->value,
        ];
    }

    private function scan(string $server, string $cache): void
    {
        $result = $server === 'media01'
            ? $this->media01->script(self::SCRIPT, [$this->media01Root, (string) $this->keep], $this->timeout)
            : $this->local->run(['sh', '-c', self::SCRIPT, 'sh', $this->media02Root, (string) $this->keep], $this->timeout);

        if ($server === 'media01' && $result->exitCode() === 255) {
            throw new \RuntimeException('media01 can\'t be reached right now.');
        }
        if (trim($result->output()) === '' && ! $result->successful()) {
            throw new \RuntimeException('The scan failed: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }

        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        file_put_contents("$cache.tmp", $result->output());
        rename("$cache.tmp", $cache);
    }
}
