<?php

namespace App\Servers;

/**
 * File operations on one media server's library, by library-relative path
 * ("TV/Drama/Show"). media02 is this machine; media01 is reached over ssh.
 *
 * Each server maps relative paths onto its own root (/home/share/Video or
 * /sharedfolders/Video). The old site passed media02 absolute paths to
 * media01, which is why its media01 operations never worked.
 *
 * Methods throw ServerOperationFailed with a message fit to show the user.
 */
interface MediaServer
{
    public function name(): string;

    /** 'dir', 'file' or null when nothing is there. */
    public function exists(string $relative): ?string;

    /** Creates the folder and any missing parents (mode 777, as before). */
    public function mkdir(string $relative): void;

    /** Moves/renames a file or folder. Refuses to overwrite. */
    public function move(string $from, string $to): void;

    /** Deletes a file, or a folder and everything in it. */
    public function delete(string $relative): void;

    /**
     * Sub-folder names of a folder (symlinked folders included).
     *
     * @return list<string>
     */
    public function folders(string $relative): array;
}
