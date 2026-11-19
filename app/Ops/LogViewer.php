<?php

namespace App\Ops;

use App\Support\Shell\LocalShell;

/**
 * Logs page (port of lib/Log.php + logs/xhr-*): only configured files can
 * be read, by key. Reads go through `tail`, so huge logs (and files over
 * 2 GB on 32-bit PHP) are never loaded whole.
 */
class LogViewer
{
    /** "Full file" shows at most this much (the end of the file). */
    public const FULL_LIMIT = 2 * 1024 * 1024;

    /**
     * @param  array<string, array{0: string, 1: string}>  $logs  key => [title, path]
     */
    public function __construct(
        private readonly LocalShell $shell,
        private readonly array $logs,
    ) {}

    /**
     * @return list<array{key: string, title: string, exists: bool, size: ?float, mtime: ?int}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->logs as $key => [$title, $path]) {
            $exists = is_file($path);
            $out[] = [
                'key' => $key,
                'title' => $title,
                'exists' => $exists,
                'size' => $exists ? $this->size($path) : null,
                'mtime' => $exists ? (int) filemtime($path) : null,
            ];
        }

        return $out;
    }

    /**
     * The last $lines lines, or (with $lines = null) the whole file up to
     * FULL_LIMIT bytes from the end.
     *
     * @return array{key: string, title: string, text: string, size: ?float, mtime: ?int, truncated: bool}
     */
    public function read(string $key, ?int $lines): array
    {
        [$title, $path] = $this->logs[$key] ?? throw new \OutOfBoundsException('Unknown log.');
        if (! is_file($path)) {
            return ['key' => $key, 'title' => $title, 'text' => '', 'size' => null, 'mtime' => null, 'truncated' => false];
        }
        if (! is_readable($path)) {
            throw new \RuntimeException('The site isn\'t allowed to read this log on the server.');
        }

        $size = $this->size($path);
        $argv = $lines !== null
            ? ['tail', '-n', (string) $lines, '--', $path]
            : ['tail', '-c', (string) self::FULL_LIMIT, '--', $path];
        $text = $this->shell->run($argv, 30)->output();
        $truncated = $lines === null && $size > self::FULL_LIMIT;
        if ($truncated) {
            // Start at a whole line.
            $text = substr($text, (int) strpos($text, "\n") + 1);
        }

        return [
            'key' => $key,
            'title' => $title,
            'text' => mb_convert_encoding($text, 'UTF-8', 'UTF-8'),
            'size' => $size,
            'mtime' => (int) filemtime($path),
            'truncated' => $truncated,
        ];
    }

    private function size(string $path): float
    {
        return (float) trim($this->shell->run(['stat', '-c', '%s', '--', $path], 10)->output());
    }
}
