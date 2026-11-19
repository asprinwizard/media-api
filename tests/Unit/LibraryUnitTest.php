<?php

use App\Library\DirectoryEntry;
use App\Library\DirectoryReader;
use App\Library\FileInspector;
use App\Library\FileType;
use App\Library\SortKey;

it('parses find output, keeping sizes over 4 GB exact', function () {
    $entries = DirectoryReader::parse("f\t5368709120\t1700000000.5\tbig film.mkv\0d\t4096\t1600000000.0\tSeries 1\0l\t20\t1500000000.0\tgone.mp4\0p\t0\t0.0\tfifo\0");

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->size)->toBe(5368709120.0)
        ->and($entries[0]->type)->toBe(FileType::Video)
        ->and($entries[0]->mtime)->toBe(1700000000)
        ->and($entries[1]->type)->toBe(FileType::Folder)
        ->and($entries[1]->size)->toBeNull()
        ->and($entries[2]->type)->toBe(FileType::Broken);
});

it('keeps tabs and odd characters inside names', function () {
    $entries = DirectoryReader::parse("f\t1\t1.0\tname\twith tab.mp4\0");

    expect($entries[0]->name)->toBe("name\twith tab.mp4");
});

it('builds sort keys and jump letters like the old site', function () {
    expect(SortKey::of('The Office'))->toBe('office')
        ->and(SortKey::of('A Quiet Place'))->toBe('quiet place')
        ->and(SortKey::of('Theatre'))->toBe('theatre')
        ->and(SortKey::letter('The Office'))->toBe('o')
        ->and(SortKey::letter('24 Hours'))->toBe('2')
        ->and(SortKey::letter('#Beings (2015)'))->toBe('#');
});

it('sorts naturally so episode 2 comes before episode 10', function () {
    $sorted = SortKey::sort([
        new DirectoryEntry('Episode 10.mp4', FileType::Video, 1.0, 0),
        new DirectoryEntry('Episode 2.mp4', FileType::Video, 1.0, 0),
    ]);

    expect(array_column(array_map(fn ($e) => (array) $e, $sorted), 'name'))->toBe(['Episode 2.mp4', 'Episode 10.mp4']);
});

it('makes getID3 output safe for JSON', function () {
    $clean = FileInspector::sanitise([
        'cover' => "\x89PNG\r\n\x1a\n\x00\x00binary",
        'title' => 'Fine',
        'long' => str_repeat('a', 600),
        'nan' => NAN,
        'nested' => [[[[[[['too deep']]]]]]],
    ]);

    expect($clean['cover'])->toStartWith('[binary')
        ->and($clean['title'])->toBe('Fine')
        ->and(mb_strlen($clean['long']))->toBe(501)
        ->and($clean['nan'])->toBeNull()
        ->and(json_encode($clean))->not->toBeFalse();
});
