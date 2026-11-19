<?php

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
    $this->lib = config('media.paths.library');
    $this->m1 = fakeMedia01($this->base);
    config()->set('media.tools.latest_cache_dir', "{$this->base}/latest");

    foreach ([$this->lib, $this->m1] as $root) {
        @mkdir("$root/TV/Shows/.@__thumb", 0777, true);
        @mkdir("$root/TV/Shows/Visible", 0777, true);
        file_put_contents("$root/TV/Shows/.DS_Store", 'x');
        file_put_contents("$root/TV/Shows/.@__thumb/hidden.mp4", 'x');
        file_put_contents("$root/TV/Shows/episode.mp4", 'x');
    }
});

it('leaves hidden files and folders out of library listings, on both servers', function () {
    expect(collect(asUser('alice')->getJson('/library?path=TV/Shows')->assertOk()->json('items'))->pluck('name')->all())
        ->toBe(['Visible', 'episode.mp4']);
    expect(collect(asUser('richard')->getJson('/admin/media01/library?path=TV/Shows')->assertOk()->json('items'))->pluck('name')->all())
        ->toBe(['Visible', 'episode.mp4']);
});

it('leaves them out of the folder pickers', function () {
    foreach (['media02', 'media01'] as $server) {
        asUser('richard')->getJson("/admin/folders?server=$server&path=TV/Shows")->assertOk()
            ->assertJsonPath('folders.*.name', ['Visible']);
    }
});

it('leaves them out of latest files and the upload folder', function () {
    foreach (['media02', 'media01'] as $server) {
        $paths = collect(asUser('richard')->getJson("/tools/latest?server=$server&refresh=1")->assertOk()->json('items'))->pluck('path');
        expect($paths)->toContain('TV/Shows/episode.mp4')
            ->and($paths->filter(fn ($p) => str_contains($p, '/.')))->toBeEmpty();
    }

    $upload = "{$this->base}/upload";
    @mkdir($upload, 0777, true);
    config()->set('media.paths.upload', $upload);
    file_put_contents("$upload/.hidden", 'x');
    file_put_contents("$upload/film.mp4", 'x');
    expect(collect(asUser('richard')->getJson('/uploads')->json('items'))->pluck('name')->all())->toBe(['film.mp4']);
});

it('refuses direct requests for hidden files and folders', function () {
    asUser('alice')->getJson('/library?path=TV/Shows/.@__thumb')->assertNotFound();
    asUser('alice')->getJson('/files/info?path=TV/Shows/.DS_Store')->assertNotFound();
    asUser('alice')->getJson('/files/play?path=TV/Shows/.@__thumb/hidden.mp4')->assertNotFound();
    asUser('alice')->get('/files/download?path=TV/Shows/.@__thumb/hidden.mp4')->assertNotFound();
    asUser('richard')->getJson('/admin/media01/library?path=TV/Shows/.@__thumb')->assertNotFound();
    asUser('richard')->getJson('/admin/folders?server=media01&path=TV/Shows/.@__thumb')->assertNotFound();
});

it('refuses to create or rename things to hidden names', function () {
    asUser('richard')->postJson('/admin/folders', ['server' => 'media02', 'path' => 'TV/Shows', 'name' => '.secret'])->assertStatus(400);
    asUser('richard')->postJson('/admin/items/move', ['server' => 'media02', 'path' => 'TV/Shows', 'items' => [['name' => 'episode.mp4', 'newName' => '.episode.mp4']]])
        ->assertOk()->assertJsonPath('results.0.ok', false);
    expect(is_file("{$this->lib}/TV/Shows/episode.mp4"))->toBeTrue();
});
