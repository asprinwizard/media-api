<?php

namespace App\Support\Paths;

use App\Auth\MediaUser;

/**
 * Builds validated MediaPaths from client input. Every endpoint that takes a
 * path goes through here (plan §4.3).
 *
 * Checks, in order:
 *  1. lexical: no NUL bytes, no ".." segments (MediaPath::normalise)
 *  2. restricted folders: non-admins can't enter e.g. "Torrent Downloads"
 *  3. containment (local roots only): the real path, after following
 *     symlinks, must be inside the root or one of the allowed mounts that the
 *     library intentionally symlinks onto
 */
class MediaPathResolver
{
    /**
     * @param  array<string, string>  $roots  name => absolute directory
     * @param  list<string>  $allowedMounts
     * @param  list<string>  $restrictedFolders  top-level library folders, admin only
     */
    public function __construct(
        private readonly array $roots,
        private readonly array $allowedMounts,
        private readonly array $restrictedFolders,
    ) {}

    public function library(string $path, MediaUser $user): MediaPath
    {
        $mediaPath = $this->make('library', $path);

        $top = $mediaPath->segments()[0] ?? null;
        if ($top !== null && ! $user->isAdmin() && in_array($top, $this->restrictedFolders, true)) {
            throw InvalidMediaPath::forbidden();
        }

        $this->assertContained($mediaPath);

        return $mediaPath;
    }

    /**
     * A library path from a signed public link. The signature proves a user
     * allowed to see the file created the link, so restricted folders are not
     * re-checked; lexical and containment checks still apply.
     */
    public function librarySigned(string $path): MediaPath
    {
        $mediaPath = $this->make('library', $path);
        $this->assertContained($mediaPath);

        return $mediaPath;
    }

    public function upload(string $path = ''): MediaPath
    {
        $mediaPath = $this->make('upload', $path);
        $this->assertContained($mediaPath);

        return $mediaPath;
    }

    /**
     * A path on media01. Only lexical checks apply: it can't be resolved
     * locally, and every remote call quotes its arguments (RemoteShell).
     */
    public function media01(string $path): MediaPath
    {
        return $this->make('media01', $path);
    }

    /**
     * Is this user allowed to see a top-level library folder by name?
     * (Used to hide restricted folders from listings.)
     */
    public function canSeeTopLevel(string $folder, MediaUser $user): bool
    {
        return $user->isAdmin() || ! in_array($folder, $this->restrictedFolders, true);
    }

    private function make(string $root, string $path): MediaPath
    {
        if (! isset($this->roots[$root])) {
            throw new \InvalidArgumentException("Unknown media root [{$root}].");
        }

        return new MediaPath(rtrim($this->roots[$root], '/'), MediaPath::normalise($path));
    }

    private function assertContained(MediaPath $path): void
    {
        $real = $this->realpathOfNearestExisting($path->absolute());
        if ($real === null) {
            // Not even the root exists; nothing can be served from it.
            throw InvalidMediaPath::outsideRoot();
        }

        $rootReal = realpath($path->root);
        $allowed = array_filter(array_merge(
            [$rootReal],
            array_map(fn (string $mount) => realpath($mount), $this->allowedMounts),
        ));

        foreach ($allowed as $base) {
            if ($real === $base || str_starts_with($real, rtrim($base, '/').'/')) {
                return;
            }
        }

        throw InvalidMediaPath::outsideRoot();
    }

    /**
     * realpath() of the path, or of its nearest existing ancestor when the
     * path doesn't exist yet (e.g. a folder about to be created).
     */
    private function realpathOfNearestExisting(string $absolute): ?string
    {
        $candidate = $absolute;
        while (true) {
            $real = realpath($candidate);
            if ($real !== false) {
                return $real;
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            $candidate = $parent;
        }
    }
}
