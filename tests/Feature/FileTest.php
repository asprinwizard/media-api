<?php

use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    it('hands the file to Apache via X-Sendfile and logs it', function () {
        $response = asUser('alice')->get('/files/download?path='.rawurlencode('TV/Drama/Show/Odd #1 & 50% + x.mp4'));

        $response->assertOk()
            ->assertHeader('X-Sendfile', "{$this->show}/Odd #1 & 50% + x.mp4");
        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->getContent())->toBe('');

        expect(activityLog($this->base))->toHaveCount(1)
            ->and(activityLog($this->base)[0])->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d : download : alice : '.preg_quote($this->show, '/').'\/Odd #1 & 50% \+ x\.mp4$/');
    });

    it('streams the file itself when X-Sendfile is off (local dev)', function () {
        config()->set('media.xsendfile', false);

        $response = asUser('alice')->get('/files/download?path=TV/Drama/Show/ep1.mp4')->assertOk();

        expect($response->headers->has('X-Sendfile'))->toBeFalse()
            ->and($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    });

    it('refuses folders, missing files, traversal and admin-only files', function () {
        asUser('alice')->get('/files/download?path=TV/Drama/Show', ['Accept' => 'application/json'])->assertNotFound();
        asUser('alice')->get('/files/download?path=TV/nope.mp4', ['Accept' => 'application/json'])->assertNotFound();
        asUser('alice')->get('/files/download?path=../../etc/passwd', ['Accept' => 'application/json'])->assertStatus(400);
        asUser('alice')->get('/files/download?path=Torrent%20Downloads/secret.mp4', ['Accept' => 'application/json'])->assertForbidden();
        asUser('richard')->get('/files/download?path=Torrent%20Downloads/secret.mp4')->assertOk();
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

describe('cast and public links', function () {
    it('gives a signed stream link for streamable files', function () {
        $url = asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep1.mp4')
            ->assertOk()
            ->assertJsonPath('streamable', true)
            ->json('url');

        expect($url)->toContain('/public/stream?')->toContain('signature=');

        // no REMOTE_USER: the Chromecast isn't logged in
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Sendfile', "{$this->show}/ep1.mp4")
            ->assertHeader('Content-Type', 'video/mp4');

        expect(end(...[activityLog($this->base)]))->toContain(' : stream : public : ');
    });

    it('gives a signed download link for files that cannot stream', function () {
        $url = asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/pilot.avi')
            ->assertJsonPath('streamable', false)
            ->json('url');

        expect($url)->toContain('/public/download?');
        $this->get($url)->assertOk();
    });

    it('rejects tampered, unsigned and expired links', function () {
        $url = asUser('alice')->getJson('/files/cast?path=TV/Drama/Show/ep1.mp4')->json('url');

        $this->get(str_replace('ep1.mp4', 'ep2.mkv', $url))->assertForbidden();
        $this->get('/public/stream?path=TV/Drama/Show/ep1.mp4')->assertForbidden();

        $this->travel(25)->hours();
        $this->get($url)->assertForbidden();
    });

    it('works through the /api alias Apache uses', function () {
        $alias = ['SCRIPT_NAME' => '/api/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php'), 'PHP_SELF' => '/api/index.php'];

        $url = $this->withServerVariables($alias + ['REMOTE_USER' => 'alice'])
            ->getJson('/api/files/cast?path='.rawurlencode('TV/Drama/Show/Odd #1 & 50% + x.mp4'))
            ->json('url');
        expect($url)->toContain('/api/public/stream?');

        $this->withServerVariables($alias)->get($url)->assertOk(); // replaces REMOTE_USER: not logged in
    });
});
