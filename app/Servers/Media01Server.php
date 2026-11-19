<?php

namespace App\Servers;

use App\Library\DirectoryEntry;
use App\Library\DirectoryReader;
use App\Library\FileType;
use App\Library\SortKey;
use App\Support\Shell\RemoteShell;

/**
 * media01, over `ssh22 root@media01`.
 *
 * Every operation is ONE ssh call running a fixed script; paths only ever
 * arrive as quoted positional arguments ($1, $2), never inside the script.
 */
class Media01Server implements MediaServer
{
    private const EXISTS = 'if [ -d "$1" ]; then echo dir; elif [ -e "$1" ] || [ -L "$1" ]; then echo file; fi';

    private const MKDIR = 'mkdir -p -m 777 -- "$1"';

    private const MOVE = '[ -e "$1" ] || [ -L "$1" ] || { echo "It no longer exists." >&2; exit 3; }; '
        .'[ -e "$2" ] || [ -L "$2" ] && { echo "Something with that name is already there." >&2; exit 3; }; '
        .'[ -d "$(dirname -- "$2")" ] || { echo "The destination folder does not exist." >&2; exit 3; }; '
        .'mv -n -- "$1" "$2"';

    private const DELETE = '[ -e "$1" ] || [ -L "$1" ] || { echo "It no longer exists." >&2; exit 3; }; rm -rf -- "$1"';

    private const LISTING = '[ -d "$1" ] || { echo "That folder is not on media01." >&2; exit 3; }; '
        .'find -L "$1" -mindepth 1 -maxdepth 1 -printf "%y\\t%s\\t%T@\\t%f\\0"; exit 0';

    private const FOLDERS = 'find -L "$1" -mindepth 1 -maxdepth 1 -type d ! -name ".*" -printf "%f\0"';

    /** Moving a big file between drives can take a while. */
    private const MOVE_TIMEOUT = 3600;

    public function __construct(
        private readonly RemoteShell $shell,
        private readonly string $root,
    ) {}

    public function name(): string
    {
        return 'media01';
    }

    public function exists(string $relative): ?string
    {
        $result = $this->shell->script(self::EXISTS, [$this->abs($relative)]);
        $this->assertReachable($result);
        $out = trim($result->output());

        return in_array($out, ['dir', 'file'], true) ? $out : null;
    }

    public function mkdir(string $relative): void
    {
        $this->check($this->shell->script(self::MKDIR, [$this->abs($relative)]), 'Couldn\'t create the folder on media01.');
    }

    public function move(string $from, string $to): void
    {
        $this->check($this->shell->script(self::MOVE, [$this->abs($from), $this->abs($to)], self::MOVE_TIMEOUT), 'The move failed on media01.');
    }

    public function delete(string $relative): void
    {
        if ($relative === '') {
            throw new ServerOperationFailed('The library root can\'t be deleted.');
        }
        $this->check($this->shell->script(self::DELETE, [$this->abs($relative)], 600), 'It couldn\'t be deleted on media01.');
    }

    public function folders(string $relative): array
    {
        $result = $this->shell->script(self::FOLDERS, [$this->abs($relative)]);
        $this->assertReachable($result);

        $entries = array_map(
            fn (string $name) => new DirectoryEntry($name, FileType::Folder, null, 0),
            array_values(array_filter(explode("\0", $result->output()), 'strlen')),
        );

        return array_map(fn (DirectoryEntry $e) => $e->name, SortKey::sort($entries));
    }

    /**
     * Full listing of a media01 folder (same format as media02's DirectoryReader).
     *
     * @return list<DirectoryEntry>
     */
    public function listing(string $relative): array
    {
        $result = $this->shell->script(self::LISTING, [$this->abs($relative)], 120);
        $this->check($result, 'Couldn\'t list the folder on media01.');

        return DirectoryReader::parse($result->output());
    }

    private function abs(string $relative): string
    {
        return $relative === '' ? $this->root : rtrim($this->root, '/').'/'.$relative;
    }

    /**
     * ssh exits 255 when it can't connect at all.
     */
    private function assertReachable($result): void
    {
        if ($result->exitCode() === 255) {
            throw new ServerOperationFailed('media01 can\'t be reached right now.');
        }
    }

    private function check($result, string $fallback): void
    {
        $this->assertReachable($result);
        if (! $result->successful()) {
            $message = trim($result->errorOutput());
            throw new ServerOperationFailed($message !== '' && strlen($message) < 300 ? $message : $fallback);
        }
    }
}
