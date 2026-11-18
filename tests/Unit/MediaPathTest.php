<?php

use App\Auth\MediaUser;
use App\Support\Paths\InvalidMediaPath;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;

beforeEach(function () {
    // library/            <- root
    //   TV/Drama/Show/ep1.mp4
    //   Movies -> ../drives/hd02/Movies   (allowed mount, like media02)
    //   Escape -> ../secret               (NOT an allowed mount)
    //   Torrent Downloads/
    $base = tempDir();
    mkdir("$base/library/TV/Drama/Show", 0777, true);
    touch("$base/library/TV/Drama/Show/ep1.mp4");
    mkdir("$base/library/Torrent Downloads", 0777, true);
    mkdir("$base/drives/hd02/Movies", 0777, true);
    touch("$base/drives/hd02/Movies/film.mp4");
    mkdir("$base/secret", 0777, true);
    touch("$base/secret/passwords.txt");
    symlink("$base/drives/hd02/Movies", "$base/library/Movies");
    symlink("$base/secret", "$base/library/Escape");

    $this->base = $base;
    $this->resolver = new MediaPathResolver(
        roots: ['library' => "$base/library", 'upload' => "$base/upload", 'media01' => '/sharedfolders/Video'],
        allowedMounts: ["$base/drives/hd02"],
        restrictedFolders: ['Torrent Downloads', 'Uploaded Files'],
    );
    $this->admin = new MediaUser('richard', ['admin', 'standard']);
    $this->standard = new MediaUser('alice', ['standard']);
});

describe('normalise', function () {
    it('strips leading, trailing and doubled slashes and "." segments', function () {
        expect(MediaPath::normalise('/TV//Drama/./Show/'))->toBe('TV/Drama/Show')
            ->and(MediaPath::normalise(''))->toBe('')
            ->and(MediaPath::normalise('/'))->toBe('');
    });

    it('rejects ".." anywhere', function (string $path) {
        MediaPath::normalise($path);
    })->throws(InvalidMediaPath::class)->with(['..', '../etc', 'TV/../../etc', 'TV\\..\\..\\etc']);

    it('rejects NUL bytes', function () {
        MediaPath::normalise("TV/a\0b");
    })->throws(InvalidMediaPath::class);

    it('does not decode again, so %2e%2e stays a literal name', function () {
        expect(MediaPath::normalise('%2e%2e/etc'))->toBe('%2e%2e/etc');
    });
});

describe('names', function () {
    it('accepts ordinary names with spaces, quotes and unicode', function (string $name) {
        MediaPath::assertValidName($name);
        expect(true)->toBeTrue();
    })->with(['Series 2 (2025)', "It's \"quoted\"", 'Café $HOME `x`; rm', 'a.b.c']);

    it('rejects names that are not a single segment', function (string $name) {
        MediaPath::assertValidName($name);
    })->throws(InvalidMediaPath::class)->with(['', '.', '..', 'a/b', 'a\\b', "a\0b"]);
});

describe('library resolver', function () {
    it('resolves a normal path', function () {
        $path = $this->resolver->library('TV/Drama/Show/ep1.mp4', $this->standard);

        expect($path->relative)->toBe('TV/Drama/Show/ep1.mp4')
            ->and($path->absolute())->toBe("{$this->base}/library/TV/Drama/Show/ep1.mp4")
            ->and($path->name())->toBe('ep1.mp4')
            ->and($path->parent()->relative)->toBe('TV/Drama/Show');
    });

    it('follows symlinks into allowed mounts', function () {
        $path = $this->resolver->library('Movies/film.mp4', $this->standard);

        expect($path->relative)->toBe('Movies/film.mp4');
    });

    it('refuses symlinks that lead anywhere else', function () {
        $this->resolver->library('Escape/passwords.txt', $this->admin);
    })->throws(InvalidMediaPath::class);

    it('allows paths that do not exist yet (checked via the nearest parent)', function () {
        $path = $this->resolver->library('TV/Drama/New Show', $this->admin);

        expect($path->relative)->toBe('TV/Drama/New Show');
    });

    it('hides restricted folders from non-admins', function () {
        $this->resolver->library('Torrent Downloads', $this->standard);
    })->throws(InvalidMediaPath::class);

    it('lets admins into restricted folders', function () {
        expect($this->resolver->library('Torrent Downloads', $this->admin)->relative)->toBe('Torrent Downloads')
            ->and($this->resolver->canSeeTopLevel('Torrent Downloads', $this->admin))->toBeTrue()
            ->and($this->resolver->canSeeTopLevel('Torrent Downloads', $this->standard))->toBeFalse();
    });

    it('returns 403 for restricted and 400 for malformed paths', function () {
        try {
            $this->resolver->library('Torrent Downloads/x', $this->standard);
        } catch (InvalidMediaPath $e) {
            expect($e->getStatusCode())->toBe(403);
        }
        try {
            $this->resolver->library('../x', $this->admin);
        } catch (InvalidMediaPath $e) {
            expect($e->getStatusCode())->toBe(400);
        }
    });

    it('builds child paths safely', function () {
        $show = $this->resolver->library('TV/Drama/Show', $this->admin);

        expect($show->child('Series 1')->relative)->toBe('TV/Drama/Show/Series 1');
        expect(fn () => $show->child('../x'))->toThrow(InvalidMediaPath::class);
    });
});

it('applies only lexical checks to media01 paths', function () {
    $path = $this->resolver->media01('/TV/Drama/');

    expect($path->absolute())->toBe('/sharedfolders/Video/TV/Drama');
    expect(fn () => $this->resolver->media01('TV/../..'))->toThrow(InvalidMediaPath::class);
});
