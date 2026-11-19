<?php

namespace App\Servers;

use App\Library\DirectoryReader;
use App\Library\FileType;
use App\Library\SortKey;
use App\Support\Shell\LocalShell;

/**
 * media02: this machine's library (/home/share/Video).
 *
 * Moves use `mv` rather than rename() because the library spans several
 * drives (rename() can't cross filesystems); `mv` copies then deletes.
 */
class LocalMediaServer implements MediaServer
{
    /** Moving a big file between drives can take a while. */
    private const MOVE_TIMEOUT = 3600;

    public function __construct(
        private readonly string $root,
        private readonly LocalShell $shell,
        private readonly DirectoryReader $reader,
    ) {}

    public function name(): string
    {
        return 'media02';
    }

    public function exists(string $relative): ?string
    {
        $path = $this->abs($relative);
        if (is_dir($path)) {
            return 'dir';
        }

        return file_exists($path) || is_link($path) ? 'file' : null;
    }

    public function mkdir(string $relative): void
    {
        $path = $this->abs($relative);
        if (is_dir($path)) {
            return;
        }
        if (! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new ServerOperationFailed("Couldn't create the folder (check permissions).");
        }
        @chmod($path, 0777);
    }

    public function move(string $from, string $to): void
    {
        if ($this->exists($from) === null) {
            throw new ServerOperationFailed('It no longer exists.');
        }
        if ($this->exists($to) !== null) {
            throw new ServerOperationFailed('Something with that name is already there.');
        }
        if ($this->exists(self::parentOf($to)) !== 'dir') {
            throw new ServerOperationFailed('The destination folder does not exist.');
        }

        $result = $this->shell->run(['mv', '-n', '--', $this->abs($from), $this->abs($to)], self::MOVE_TIMEOUT);
        if (! $result->successful() || $this->exists($to) === null) {
            throw new ServerOperationFailed(trim($result->errorOutput()) ?: 'The move failed.');
        }
    }

    public function delete(string $relative): void
    {
        if ($relative === '') {
            throw new ServerOperationFailed('The library root can\'t be deleted.');
        }
        $path = $this->abs($relative);
        $type = $this->exists($relative);
        if ($type === null) {
            throw new ServerOperationFailed('It no longer exists.');
        }

        if ($type === 'dir' && ! is_link($path)) {
            $result = $this->shell->run(['rm', '-rf', '--', $path], 600);
            $ok = $result->successful() && ! file_exists($path);
        } else {
            $ok = @unlink($path);
        }

        if (! $ok) {
            throw new ServerOperationFailed('It couldn\'t be deleted (check permissions).');
        }
    }

    public function folders(string $relative): array
    {
        $folders = array_filter(
            $this->reader->read($this->abs($relative)),
            fn ($entry) => $entry->type === FileType::Folder,
        );

        return array_map(fn ($entry) => $entry->name, SortKey::sort(array_values($folders)));
    }

    private static function parentOf(string $relative): string
    {
        $slash = strrpos($relative, '/');

        return $slash === false ? '' : substr($relative, 0, $slash);
    }

    private function abs(string $relative): string
    {
        return $relative === '' ? $this->root : rtrim($this->root, '/').'/'.$relative;
    }
}
