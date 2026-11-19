<?php

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
});

describe('search', function () {
    it('finds folders and files by name, case-insensitively', function () {
        $results = asUser('alice')->getJson('/search?q=LANTERN')->assertOk()->json('results');

        expect($results)->toBe([
            ['name' => 'The Lantern Keeper (2019)', 'path' => 'Movies/The Lantern Keeper (2019)', 'type' => 'folder'],
            ['name' => 'The Lantern Keeper (2019).mp4', 'path' => 'Movies/The Lantern Keeper (2019)/The Lantern Keeper (2019).mp4', 'type' => 'video'],
        ]);
    });

    it('treats the term as plain text, not a pattern or shell code', function () {
        expect(asUser('alice')->getJson('/search?q=.*')->assertOk()->json('results'))->toBe([]);
        expect(asUser('alice')->getJson('/search?q='.rawurlencode("'; rm -rf / #"))->assertOk()->json('results'))->toBe([]);
        expect(asUser('alice')->getJson('/search?q='.rawurlencode('#1 & 50%'))->json('results.0.name'))->toBe('Odd #1 & 50% + x.mp4');
    });

    it('keeps paths that contain a colon intact', function () {
        expect(asUser('alice')->getJson('/search?q=colon')->json('results.0.path'))->toBe('TV/Drama/Show/what: a colon.mp4');
    });

    it('leaves out hidden files and folders listed in the index', function () {
        file_put_contents("{$this->base}/search/tv.db", "TV/Drama/Show/.thumbs/ep1-hidden.jpg\nTV/Drama/Show/.hidden-ep.mp4\n", FILE_APPEND);
        expect(collect(asUser('alice')->getJson('/search?q=hidden')->assertOk()->json('results'))->pluck('path')->all())->toBe([]);
    });

    it('hides admin-only folders from other users', function () {
        expect(asUser('alice')->getJson('/search?q=secret')->json('results'))->toBe([]);
        expect(asUser('richard')->getJson('/search?q=secret')->json('results.0.path'))->toBe('Torrent Downloads/secret.mp4');
    });

    it('caps the number of results', function () {
        config()->set('media.search_limit', 2);

        asUser('alice')->getJson('/search?q=tv')->assertJsonCount(2, 'results')->assertJsonPath('truncated', true);
    });

    it('logs each search', function () {
        asUser('alice')->getJson('/search?q=lantern');

        expect(file("{$this->base}/public/logs/file_searches.log", FILE_IGNORE_NEW_LINES)[0])
            ->toMatch('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d : alice : lantern$/');
    });

    it('needs at least two characters', function () {
        asUser('alice')->getJson('/search?q=a')->assertStatus(422);
        asUser('alice')->getJson('/search')->assertStatus(422);
    });
});

describe('dashboard', function () {
    it('shows admins the last 20 lines of the media01 logs', function () {
        file_put_contents("{$this->base}/logs/media01-tv.log", implode("\n", array_map(fn ($i) => "line $i", range(1, 30)))."\n");

        $logs = asUser('richard')->getJson('/dashboard')->assertOk()->json('logs');

        expect($logs[0]['title'])->toBe('TV')
            ->and($logs[0]['lines'])->toHaveCount(20)
            ->and($logs[0]['lines'][19])->toBe('line 30')
            ->and($logs[1]['lines'])->toBe([]); // movies log missing
    });

    it('shows other users nothing', function () {
        asUser('alice')->getJson('/dashboard')->assertOk()->assertExactJson(['logs' => []]);
    });
});
