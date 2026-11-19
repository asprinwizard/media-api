<?php

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
});

function names($response): array
{
    return collect($response->json('items'))->pluck('name')->all();
}

it('lists the root, hiding admin-only folders from other users', function () {
    expect(names(asUser('richard')->getJson('/library')->assertOk()))
        ->toBe(['Movies', 'Torrent Downloads', 'TV']);

    expect(names(asUser('alice')->getJson('/library')->assertOk()))
        ->toBe(['Movies', 'TV']);
});

it('refuses admin-only folders to other users even by direct path', function () {
    asUser('alice')->getJson('/library?path=Torrent%20Downloads')->assertForbidden();
    asUser('richard')->getJson('/library?path=Torrent%20Downloads')->assertOk();
});

it('sorts folders first, ignores "The"/"A", and keeps names whose keys collide', function () {
    $response = asUser('alice')->getJson('/library?path=Movies')->assertOk();

    // "The Office" and "Office" both share the key "office"; the old site dropped one
    expect(names($response))->toBe(['Ember (2023)', 'The Lantern Keeper (2019)', 'Office', 'The Office'])
        ->and($response->json('alpha'))->toBeTrue()
        ->and(collect($response->json('items'))->pluck('letter')->all())->toBe(['e', 'l', 'o', 'o']);
});

it('describes each entry with type, size and date', function () {
    $items = collect(asUser('alice')->getJson('/library?path=TV/Drama/Show')->assertOk()->json('items'))->keyBy('name');

    expect($items['ep1.mp4'])->toMatchArray(['type' => 'video', 'size' => 10, 'mtime' => 1_700_000_000, 'path' => 'TV/Drama/Show/ep1.mp4'])
        ->and($items['theme.m4a']['type'])->toBe('audio')
        ->and($items['ep1.srt']['type'])->toBe('subtitle')
        ->and($items['extras.zip']['type'])->toBe('compressed')
        ->and($items['notes.txt']['type'])->toBe('file')
        ->and($items['gone.mp4']['type'])->toBe('broken')
        ->and($items['gone.mp4']['size'])->toBeNull();
});

it('sorts by date and in reverse, keeping folders first', function () {
    $byDate = names(asUser('alice')->getJson('/library?path=TV/Drama/Show&sort=date')->assertOk());
    expect(array_search('ep2.mkv', $byDate))->toBeLessThan(array_search('ep1.mp4', $byDate));

    $desc = names(asUser('alice')->getJson('/library?path=Movies&dir=desc')->assertOk());
    expect($desc)->toBe(['The Office', 'Office', 'The Lantern Keeper (2019)', 'Ember (2023)']);
});

it('only shows the A–Z rail in alpha folders sorted by name', function () {
    expect(asUser('alice')->getJson('/library?path=Movies&sort=date')->json('alpha'))->toBeFalse()
        ->and(asUser('alice')->getJson('/library?path=TV')->json('alpha'))->toBeFalse();
});

it('returns breadcrumbs from the library root', function () {
    asUser('alice')->getJson('/library?path=TV/Drama/Show')
        ->assertJsonPath('breadcrumbs', [
            ['name' => 'Video', 'path' => ''],
            ['name' => 'TV', 'path' => 'TV'],
            ['name' => 'Drama', 'path' => 'TV/Drama'],
            ['name' => 'Show', 'path' => 'TV/Drama/Show'],
        ]);
});

it('rejects traversal, missing folders and bad sort options', function () {
    asUser('alice')->getJson('/library?path=../../etc')->assertStatus(400);
    asUser('alice')->getJson('/library?path=Nope')->assertNotFound();
    asUser('alice')->getJson('/library?path=TV/Drama/Show/ep1.mp4')->assertNotFound();
    asUser('alice')->getJson('/library?sort=size')->assertStatus(422);
});
