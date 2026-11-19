<?php

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
    $this->lib = "{$this->base}/share/Video";
    $this->m1 = fakeMedia01($this->base);
    mkdir("{$this->m1}/TV/Drama/Show", 0777, true);
    file_put_contents("{$this->m1}/TV/Drama/Show/ep1.mp4", 'x');
    mkdir("{$this->m1}/Movies", 0777, true);
    $this->admin = fn () => asUser('richard');
});

const NASTY = "It's \"odd\" \$HOME `id`; | & ünï.mp4";

it('is admin only', function () {
    asUser('alice')->getJson('/admin/folders?server=media02')->assertForbidden();
    asUser('alice')->postJson('/admin/items/delete', ['server' => 'media02', 'path' => 'TV', 'names' => ['x']])->assertForbidden();
    asUser('alice')->getJson('/admin/copy-queue')->assertForbidden();
});

describe('servers and folder pickers', function () {
    it('offers media01 only where the folder exists there', function () {
        asUser('richard')->getJson('/admin/servers?path=TV/Drama/Show')->assertJsonPath('media01', true);
        asUser('richard')->getJson('/admin/servers?path=Movies/Ember%20(2023)')->assertJsonPath('media01', false);
    });

    it('says when media01 is unreachable instead of failing', function () {
        fakeMedia01($this->base, reachable: false);
        asUser('richard')->getJson('/admin/servers?path=TV')
            ->assertOk()->assertJsonPath('media01', false)
            ->assertJsonPath('media01Error', "media01 can't be reached right now.");
    });

    it('lists sub-folders on either server, sorted', function () {
        expect(collect(asUser('richard')->getJson('/admin/folders?server=media02&path=Movies')->assertOk()->json('folders'))->pluck('name')->all())
            ->toBe(['Ember (2023)', 'The Lantern Keeper (2019)', 'Office', 'The Office']);

        mkdir("{$this->m1}/TV/Comedy");
        asUser('richard')->getJson('/admin/folders?server=media01&path=TV')
            ->assertOk()->assertJsonPath('folders.*.name', ['Comedy', 'Drama'])
            ->assertJsonPath('folders.1.path', 'TV/Drama');
    });

    it('rejects traversal on both servers', function () {
        asUser('richard')->getJson('/admin/folders?server=media02&path=../../etc')->assertStatus(400);
        asUser('richard')->getJson('/admin/folders?server=media01&path=../../etc')->assertStatus(400);
    });
});

describe('create folder', function () {
    it('creates folders on media02 and media01 (with awkward names)', function () {
        asUser('richard')->postJson('/admin/folders', ['server' => 'media02', 'path' => 'TV/Drama', 'name' => 'Series 3 (2027)'])->assertCreated();
        asUser('richard')->postJson('/admin/folders', ['server' => 'media01', 'path' => 'TV/Drama', 'name' => '$(touch pwned) "x"'])->assertCreated();

        expect(is_dir("{$this->lib}/TV/Drama/Series 3 (2027)"))->toBeTrue()
            ->and(is_dir("{$this->m1}/TV/Drama/\$(touch pwned) \"x\""))->toBeTrue()
            ->and(file_exists(base_path('pwned')))->toBeFalse()
            ->and(file_exists("{$this->m1}/TV/Drama/pwned"))->toBeFalse();
    });

    it('refuses existing names and names with slashes', function () {
        asUser('richard')->postJson('/admin/folders', ['server' => 'media02', 'path' => 'TV/Drama', 'name' => 'Show'])->assertStatus(422);
        asUser('richard')->postJson('/admin/folders', ['server' => 'media02', 'path' => 'TV', 'name' => '../escape'])->assertStatus(400);
    });
});

describe('move and rename', function () {
    it('renames in place on media02', function () {
        asUser('richard')->postJson('/admin/items/move', [
            'server' => 'media02', 'path' => 'TV/Drama/Show',
            'items' => [['name' => 'ep1.mp4', 'newName' => 'S01E01 - Pilot.mp4']],
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('results.0.newPath', 'TV/Drama/Show/S01E01 - Pilot.mp4');

        expect(is_file("{$this->lib}/TV/Drama/Show/S01E01 - Pilot.mp4"))->toBeTrue()
            ->and(file_exists("{$this->lib}/TV/Drama/Show/ep1.mp4"))->toBeFalse();
    });

    it('moves into a new sub-folder of another folder, across drives', function () {
        asUser('richard')->postJson('/admin/items/move', [
            'server' => 'media02', 'path' => 'TV/Drama/Show',
            'destination' => 'Movies', 'newFolder' => 'From TV',
            'items' => [['name' => 'ep2.mkv'], ['name' => 'theme.m4a', 'newName' => 'Theme.m4a']],
        ])->assertOk()->assertJsonPath('ok', true);

        // Movies is a symlink onto another "drive" (drives/hd02)
        expect(is_file("{$this->base}/drives/hd02/Movies/From TV/ep2.mkv"))->toBeTrue()
            ->and(is_file("{$this->base}/drives/hd02/Movies/From TV/Theme.m4a"))->toBeTrue();
    });

    it('moves and renames on media01, with hostile names', function () {
        file_put_contents("{$this->m1}/TV/Drama/Show/".NASTY, 'x');

        asUser('richard')->postJson('/admin/items/move', [
            'server' => 'media01', 'path' => 'TV/Drama/Show',
            'items' => [['name' => NASTY, 'newName' => 'Renamed $(id).mp4'], ['name' => 'ep1.mp4', 'newName' => 'S01E01.mp4']],
        ])->assertOk()->assertJsonPath('ok', true);

        expect(is_file("{$this->m1}/TV/Drama/Show/Renamed \$(id).mp4"))->toBeTrue()
            ->and(is_file("{$this->m1}/TV/Drama/Show/S01E01.mp4"))->toBeTrue();
    });

    it('reports each failure without stopping the rest', function () {
        $response = asUser('richard')->postJson('/admin/items/move', [
            'server' => 'media02', 'path' => 'TV/Drama/Show',
            'items' => [
                ['name' => 'ep1.mp4', 'newName' => 'ep2.mkv'],          // would overwrite
                ['name' => 'missing.mp4', 'newName' => 'x.mp4'],        // gone
                ['name' => 'notes.txt', 'newName' => 'notes.txt'],      // same name, same place
                ['name' => 'theme.m4a', 'newName' => '../../x.m4a'],    // traversal
                ['name' => 'ep1.srt', 'newName' => 'S01E01.srt'],       // fine
            ],
        ])->assertOk()->assertJsonPath('ok', false)->assertJsonPath('failed', 4);

        expect(collect($response->json('results'))->pluck('ok')->all())->toBe([false, false, false, false, true])
            ->and($response->json('results.0.error'))->toBe('Something with that name is already there.')
            ->and(is_file("{$this->lib}/TV/Drama/Show/ep1.mp4"))->toBeTrue();
    });

    it('moves folders too', function () {
        asUser('richard')->postJson('/admin/items/move', [
            'server' => 'media01', 'path' => 'TV/Drama', 'destination' => 'Movies',
            'items' => [['name' => 'Show', 'newName' => 'A Show']],
        ])->assertOk()->assertJsonPath('ok', true);

        expect(is_file("{$this->m1}/Movies/A Show/ep1.mp4"))->toBeTrue();
    });
});

describe('delete', function () {
    it('deletes files and folders on media02', function () {
        asUser('richard')->postJson('/admin/items/delete', ['server' => 'media02', 'path' => 'TV/Drama/Show', 'names' => ['ep1.mp4', 'gone.mp4']])
            ->assertOk()->assertJsonPath('ok', true);
        asUser('richard')->postJson('/admin/items/delete', ['server' => 'media02', 'path' => 'TV/Drama', 'names' => ['Show']])
            ->assertOk()->assertJsonPath('ok', true);

        expect(file_exists("{$this->lib}/TV/Drama/Show"))->toBeFalse();
    });

    it('deletes on media01 and reports missing items', function () {
        asUser('richard')->postJson('/admin/items/delete', ['server' => 'media01', 'path' => 'TV/Drama/Show', 'names' => ['ep1.mp4', 'nope.mp4']])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', false)
            ->assertJsonPath('results.1.error', 'It no longer exists.');
    });

    it('never deletes through a symlinked folder into the drive behind it', function () {
        // "Movies" is a symlink: deleting it removes the link, not the films
        asUser('richard')->postJson('/admin/items/delete', ['server' => 'media02', 'path' => '', 'names' => ['Movies']])->assertOk();

        expect(is_file("{$this->base}/drives/hd02/Movies/The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4"))->toBeTrue();
    });
});

describe('copy to media01 queue', function () {
    beforeEach(function () {
        $this->queue = "{$this->base}/public/admin/file_copy_queue";
        mkdir(dirname($this->queue), 0777, true);
        config()->set('media.copy_queue', $this->queue);
        file_put_contents("{$this->lib}/TV/Drama/Show/".NASTY, 'x');
        file_put_contents("{$this->lib}/TV/Drama/Show/Film (SD).mp4", 'x');
    });

    it('queues lines that run safely under bash, with exactly the intended arguments', function () {
        asUser('richard')->postJson('/admin/copy-queue', [
            'path' => 'TV/Drama/Show', 'destination' => 'TV/Drama', 'newFolder' => 'Show $(id)', 'hd' => true,
            'files' => [['name' => NASTY], ['name' => 'Film (SD).mp4']],
        ])->assertCreated()->assertJsonPath('queued', 2)->assertJsonCount(3, 'pending');

        // Run the queue file the way cron does, with stand-in ssh/rsync that
        // record their argv (one argument per line).
        $bin = "{$this->base}/fakebin";
        mkdir($bin);
        foreach (['ssh', 'rsync'] as $cmd) {
            file_put_contents("$bin/$cmd", "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done >> '{$this->base}/$cmd.argv'\necho --- >> '{$this->base}/$cmd.argv'\n");
            chmod("$bin/$cmd", 0755);
        }
        exec('PATH='.escapeshellarg($bin).':$PATH bash '.escapeshellarg($this->queue).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        $remoteDir = "{$this->m1}/TV/Drama/Show \$(id)/HD";
        expect(file("{$this->base}/ssh.argv", FILE_IGNORE_NEW_LINES))->toBe([
            '-p', '22', 'root@media01', 'mkdir -p -m 777 -- '.escapeshellarg($remoteDir), '---',
        ]);

        $rsync = file("{$this->base}/rsync.argv", FILE_IGNORE_NEW_LINES);
        $first = array_slice($rsync, 0, array_search('---', $rsync));
        expect($first)->toBe([
            '-avzL', '--recursive', '--chown=root:users', '--out-format=%t %n%L', '--rsh=ssh -p22',
            '--protect-args', '--keep-dirlinks', '--',
            "{$this->lib}/TV/Drama/Show/".NASTY,
            "root@media01:$remoteDir/".NASTY,
        ]);
        // " (SD).mp4" loses " (SD)" on media01, as before
        expect($rsync)->toContain("root@media01:$remoteDir/Film.mp4");

        // and the remote mkdir command parses to exactly one path on media01
        exec('sh -c '.escapeshellarg('printf "%s\n" '.substr('mkdir -p -m 777 -- '.escapeshellarg($remoteDir), strlen('mkdir -p -m 777 -- '))), $parsed);
        expect($parsed)->toBe([$remoteDir]);
    });

    it('shows what is waiting and what is running', function () {
        file_put_contents("{$this->queue}_active", "rsync something\n");
        asUser('richard')->getJson('/admin/copy-queue')->assertOk()
            ->assertJsonPath('pending', [])->assertJsonPath('running', ['rsync something']);
    });

    it('refuses non-files and a destination at the root', function () {
        asUser('richard')->postJson('/admin/copy-queue', ['path' => 'TV/Drama', 'destination' => 'TV', 'files' => [['name' => 'Show']]])->assertStatus(422);
        asUser('richard')->postJson('/admin/copy-queue', ['path' => 'TV/Drama/Show', 'destination' => '', 'files' => [['name' => 'ep1.mp4']]])->assertStatus(422);
    });
});

it('gives admins name suggestions in listings, and nobody else', function () {
    file_put_contents("{$this->lib}/TV/Drama/Show/Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4", 'x');

    $admin = collect(asUser('richard')->getJson('/library?path=TV/Drama/Show')->json('items'))->keyBy('name');
    $standard = collect(asUser('alice')->getJson('/library?path=TV/Drama/Show')->json('items'))->keyBy('name');

    expect($admin['Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4']['suggestedName'])->toBe('S02E05 - Undertow.mp4')
        ->and($standard['Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4'])->not->toHaveKey('suggestedName');
});
