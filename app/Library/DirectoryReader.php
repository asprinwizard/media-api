<?php

namespace App\Library;

use App\Support\Shell\LocalShell;

/**
 * Lists one directory with a single `find` call.
 *
 * Why not scandir() + filesize(): on 32-bit PHP (media02) filesize()/stat()
 * fail for files over 2 GB, and one process per folder is far cheaper than
 * one `stat` per file. GNU find uses 64-bit sizes.
 *
 * -L follows symlinks (the library is built from them), so sizes and types
 * are those of the targets; a symlink whose target is missing stays type "l".
 */
class DirectoryReader
{
    public function __construct(private readonly LocalShell $shell) {}

    /**
     * @return list<DirectoryEntry>
     */
    public function read(string $directory): array
    {
        $result = $this->shell->run([
            'find', '-L', $directory, '-mindepth', '1', '-maxdepth', '1',
            '-printf', '%y\t%s\t%T@\t%f\0',
        ], timeout: 120);

        // find exits non-zero for e.g. symlink loops but still lists the rest.
        return self::parse($result->output());
    }

    /**
     * @return list<DirectoryEntry>
     */
    public static function parse(string $output): array
    {
        $entries = [];
        foreach (explode("\0", $output) as $record) {
            if ($record === '') {
                continue;
            }
            $parts = explode("\t", $record, 4);
            if (count($parts) !== 4) {
                continue;
            }
            [$kind, $size, $mtime, $name] = $parts;

            $type = match ($kind) {
                'd' => FileType::Folder,
                'f' => FileType::fromFileName($name),
                'l' => FileType::Broken,
                default => null, // sockets, devices, …: not shown
            };
            if ($type === null) {
                continue;
            }

            $entries[] = new DirectoryEntry(
                name: $name,
                type: $type,
                size: $kind === 'f' ? (float) $size : null,
                mtime: (int) $mtime,
            );
        }

        return $entries;
    }
}
