<?php

use App\Auth\HtgroupRepository;
use App\Support\Paths\MediaPathResolver;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Write a temporary .htgroup file and point config at it.
 */
function useHtgroup(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'htgroup');
    file_put_contents($file, $contents);
    config()->set('media.auth.htgroup_file', $file);
    app()->forgetInstance(HtgroupRepository::class);

    return $file;
}

/**
 * Make a fresh temporary directory and return its real path.
 */
function tempDir(string $prefix = 'media'): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return realpath($dir);
}

/**
 * Build a small library shaped like media02 and point config at it.
 *
 *   share/Video/
 *     Movies -> drives/hd02/Movies      (symlink onto an allowed mount)
 *       The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4
 *       Office/ , The Office/            (sort keys collide)
 *       Ember (2023)/
 *     TV/Drama/Show/ ep1.mp4, ep2.mkv, pilot.avi (real AVI header), notes.txt,
 *                    theme.m4a, extras.zip, ep1.srt, "Odd #1 & 50% + x.mp4",
 *                    gone.mp4 -> missing target (broken link)
 *     Torrent Downloads/secret.mp4      (admin only)
 *   public/logs/                         (activity logs)
 *   search/movies.db, tv.db              (search index)
 */
function makeLibrary(): string
{
    $base = tempDir('library');
    $lib = "$base/share/Video";
    $show = "$lib/TV/Drama/Show";
    foreach ([$show, "$lib/Torrent Downloads", "$base/drives/hd02/Movies/The Lantern Keeper (2019)",
        "$base/drives/hd02/Movies/Office", "$base/drives/hd02/Movies/The Office",
        "$base/drives/hd02/Movies/Ember (2023)", "$base/public/logs", "$base/search", "$base/logs"] as $dir) {
        mkdir($dir, 0777, true);
    }
    symlink("$base/drives/hd02/Movies", "$lib/Movies");
    file_put_contents("$base/drives/hd02/Movies/The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4", 'x');
    foreach (['ep1.mp4', 'ep2.mkv', 'notes.txt', 'theme.m4a', 'extras.zip', 'ep1.srt', 'Odd #1 & 50% + x.mp4'] as $f) {
        file_put_contents("$show/$f", str_repeat('x', 10));
    }
    file_put_contents("$show/pilot.avi", "RIFF\x24\x00\x00\x00AVI LIST\x00\x00\x00\x00hdrlavih");
    symlink("$base/nowhere/gone.mp4", "$show/gone.mp4");
    touch("$show/ep1.mp4", 1_700_000_000);
    touch("$show/ep2.mkv", 1_600_000_000);
    file_put_contents("$lib/Torrent Downloads/secret.mp4", 'x');

    file_put_contents("$base/search/movies.db", "Movies\nMovies/The Lantern Keeper (2019)\nMovies/The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4\n");
    file_put_contents("$base/search/tv.db", "TV\nTV/Drama\nTV/Drama/Show\nTV/Drama/Show/ep1.mp4\nTV/Drama/Show/Odd #1 & 50% + x.mp4\nTorrent Downloads/secret.mp4\nTV/Drama/Show/what: a colon.mp4\n");

    config()->set('media.paths.share', "$base/share");
    config()->set('media.paths.library', $lib);
    config()->set('media.paths.public', "$base/public");
    config()->set('media.paths.log_dir', "$base/logs");
    config()->set('media.allowed_mounts', ["$base/drives/hd02"]);
    config()->set('media.search_index', "$base/search");
    config()->set('media.cast.path', "$base/public-cast");
    app()->forgetInstance(MediaPathResolver::class);

    return $base;
}

function asUser(string $username): TestCase
{
    return test()->withServerVariables(['REMOTE_USER' => $username]);
}

/**
 * A fake media01: a temp folder plus a fake `ssh22` that runs the remote
 * command with sh, exactly as ssh + the remote shell would (ssh joins its
 * arguments with spaces; the remote shell parses the result once).
 * Returns the fake media01 library root.
 */
function fakeMedia01(string $base, bool $reachable = true): string
{
    $root = "$base/media01/Video";
    @mkdir($root, 0777, true);
    $script = "$base/bin/ssh22";
    @mkdir(dirname($script), 0777, true);
    file_put_contents($script, $reachable
        ? "#!/bin/sh\nshift\nexec sh -c \"\$*\"\n"
        : "#!/bin/sh\necho 'ssh: connect to host media01 port 22: No route to host' >&2\nexit 255\n");
    chmod($script, 0755);

    config()->set('media.media01.ssh', $script);
    config()->set('media.media01.root', $root);

    return $root;
}
