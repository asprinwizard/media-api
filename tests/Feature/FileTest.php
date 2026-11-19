<?php

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
    $this->show = "{$this->base}/share/Video/TV/Drama/Show";
});

function activityLog(string $base): array
{
    $file = "$base/public/logs/file_actions.log";

    return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
}

describe('download', function () {
    it('logs the download and redirects to the Apache-served file with ?download', function () {
        $response = asUser('alice')->get('/files/download?path='.rawurlencode('TV/Drama/Show/Odd #1 & 50% + x.mp4'));

        // Relative on purpose: the browser stays on the same host, Apache serves the file
        $response->assertStatus(302)
            ->assertHeader('Location', '/media/Video/TV/Drama/Show/Odd%20%231%20%26%2050%25%20%2B%20x.mp4?download');

        expect(activityLog($this->base))->toHaveCount(1)
            ->and(activityLog($this->base)[0])->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d : download : alice : '.preg_quote($this->show, '/').'\/Odd #1 & 50% \+ x\.mp4$/');
    });

    it('keeps the redirect relative when served under the /api alias', function () {
        $this->withServerVariables(['REMOTE_USER' => 'alice', 'SCRIPT_NAME' => '/api/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php'), 'PHP_SELF' => '/api/index.php'])
            ->get('/api/files/download?path=TV/Drama/Show/ep1.mp4')
            ->assertHeader('Location', '/media/Video/TV/Drama/Show/ep1.mp4?download');
    });

    it('refuses folders, missing files, traversal and admin-only files', function () {
        asUser('alice')->get('/files/download?path=TV/Drama/Show', ['Accept' => 'application/json'])->assertNotFound();
        asUser('alice')->get('/files/download?path=TV/nope.mp4', ['Accept' => 'application/json'])->assertNotFound();
        asUser('alice')->get('/files/download?path=../../etc/passwd', ['Accept' => 'application/json'])->assertStatus(400);
        asUser('alice')->get('/files/download?path=Torrent%20Downloads/secret.mp4', ['Accept' => 'application/json'])->assertForbidden();
        asUser('richard')->get('/files/download?path=Torrent%20Downloads/secret.mp4')->assertStatus(302);
        expect(activityLog($this->base))->toHaveCount(1);
    });
});

describe('play', function () {
    it('returns an encoded /media URL, playable siblings, and logs the play', function () {
        asUser('alice')->getJson('/files/play?path='.rawurlencode('TV/Drama/Show/Odd #1 & 50% + x.mp4'))
            ->assertOk()
            ->assertJsonPath('src', '/media/Video/TV/Drama/Show/Odd%20%231%20%26%2050%25%20%2B%20x.mp4')
            ->assertJsonPath('siblings.*.name', ['ep1.mp4', 'ep2.mkv', 'Odd #1 & 50% + x.mp4', 'pilot.avi', 'theme.m4a']);

        expect(activityLog($this->base)[0])->toContain(' : play : alice : ');
    });

    it('marks DivX AVI as not streamable', function () {
        asUser('alice')->getJson('/files/play?path=TV/Drama/Show/pilot.avi')
            ->assertOk()
            ->assertJsonPath('mime', 'video/x-msvideo')
            ->assertJsonPath('streamable', false);
    });

    it('refuses files that are not audio or video', function () {
        asUser('alice')->getJson('/files/play?path=TV/Drama/Show/notes.txt')->assertStatus(422);
    });
});

describe('info', function () {
    it('gives everyone the basics and admins the details', function () {
        $basic = asUser('alice')->getJson('/files/info?path=TV/Drama/Show/ep1.mp4')->assertOk();
        expect($basic->json())->toHaveKeys(['general'])->not->toHaveKey('admin')
            ->and($basic->json('general.name'))->toBe('ep1.mp4')
            ->and($basic->json('general.size'))->toEqual(10);

        $admin = asUser('richard')->getJson('/files/info?path=TV/Drama/Show/ep1.mp4')->assertOk();
        expect($admin->json('admin'))->toHaveKeys(['path', 'created', 'modified', 'video', 'audio', 'comments', 'raw'])
            ->and($admin->json('admin.path'))->toBe("{$this->show}/ep1.mp4");
    });
});

describe('file-link logging', function () {
    it('logs a click on a file name', function () {
        asUser('alice')->postJson('/files/link', ['path' => 'TV/Drama/Show/ep1.mp4'])->assertOk();

        expect(activityLog($this->base)[0])->toContain(" : file link : alice : {$this->show}/ep1.mp4");
    });
});

describe('cast links', function () {
    it('makes a random folder with a symlink to the file, served at /shared/', function () {
        $response = asUser('alice')->getJson('/files/cast?path='.rawurlencode('TV/Drama/Show/Odd #1 & 50% + x.mp4'))
            ->assertOk()
            ->assertJsonPath('streamable', true);

        $url = $response->json('url');
        expect($url)->toMatch('#^/shared/[0-9a-f]{32}/Odd%20%231%20%26%2050%25%20%2B%20x\.mp4$#')
            ->and($response->json('expires'))->toBeGreaterThan(time() + 23 * 3600);

        $token = explode('/', $url)[2];
        $link = "{$this->base}/public-cast/{$token}/Odd #1 & 50% + x.mp4";
        expect(is_link($link))->toBeTrue()
            ->and(readlink($link))->toBe("{$this->show}/Odd #1 & 50% + x.mp4");

        expect(end(...[activityLog($this->base)]))->toContain(' : stream : alice : ');
    });

    it('gives a download link for files that cannot stream', function () {
        asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/pilot.avi')
            ->assertJsonPath('streamable', false)
            ->assertJsonPath('url', fn (string $url) => str_ends_with($url, '/pilot.avi?download'));
    });

    it('uses a new unguessable folder each time', function () {
        $a = asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep1.mp4')->json('url');
        $b = asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep1.mp4')->json('url');

        expect($a)->not->toBe($b);
    });

    it('removes expired link folders but never real files or other folders', function () {
        $root = "{$this->base}/public-cast";
        asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep1.mp4');
        $old = glob("$root/*")[0];
        touch($old, time() - 25 * 3600);
        mkdir("$root/not-a-token");
        touch("$root/not-a-token", time() - 25 * 3600);

        asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep2.mkv');

        expect(is_dir($old))->toBeFalse()
            ->and(is_dir("$root/not-a-token"))->toBeTrue()
            ->and(is_file("{$this->show}/ep1.mp4"))->toBeTrue()
            ->and(glob("$root/*"))->toHaveCount(2);
    });

    it('refuses admin-only files to other users', function () {
        asUser('alice')->getJson('/files/cast?path=Torrent%20Downloads/secret.mp4')->assertForbidden();
    });
});
