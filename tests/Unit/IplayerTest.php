<?php

use App\Acquire\Iplayer\IplayerInfo;
use App\Acquire\Iplayer\IplayerService;
use App\Acquire\Iplayer\IplayerStatusResolver as R;
use App\Library\DirectoryEntry;
use App\Library\FileType;

function ipFiles(array $files): array
{
    // name => [size, mtime]
    return array_map(
        fn ($name, $meta) => new DirectoryEntry($name, FileType::fromFileName($name), (float) $meta[0], $meta[1] ?? 1000),
        array_keys($files),
        $files,
    );
}

describe('search output', function () {
    it('reads the matches between "Matches:" and "INFO:"', function () {
        $results = IplayerService::parseSearch(file_get_contents(base_path('tests/fixtures/iplayer/search.txt')));
        expect($results)->toHaveCount(3)
            ->and($results[0])->toBe(['index' => '1234', 'label' => 'Doctor Who: Series 14 - 1. Space Babies, BBC One, m001z3k1', 'pid' => 'm001z3k1'])
            ->and($results[2]['pid'])->toBe('m0025abc');
    });

    it('returns nothing when there are no matches', function () {
        expect(IplayerService::parseSearch("get_iplayer v3.35\n\nMatches:\n\nINFO: 0 matching programmes\n"))->toBe([]);
    });

    it('accepts PIDs and iPlayer links, and nothing else', function () {
        expect(IplayerService::normalisePid('m001z3k1'))->toBe('m001z3k1')
            ->and(IplayerService::normalisePid(' B006M86D '))->toBe('b006m86d')
            ->and(IplayerService::normalisePid('https://www.bbc.co.uk/iplayer/episode/m001z3k1/doctor-who-series-14-1-space-babies'))->toBe('m001z3k1')
            ->and(IplayerService::normalisePid('https://www.bbc.co.uk/programmes/b006m86d'))->toBe('b006m86d')
            ->and(IplayerService::normalisePid('m001z3k1; rm -rf /'))->toBeNull()
            ->and(IplayerService::normalisePid('$(id)'))->toBeNull()
            ->and(IplayerService::normalisePid('https://evil.example/iplayer/episode/m001z3k1'))->toBeNull();
    });
});

describe('fileinfo', function () {
    it('gives the title and the best expected size', function () {
        $info = IplayerInfo::parse(file_get_contents(base_path('tests/fixtures/iplayer/fileinfo.txt')));
        expect($info->title())->toBe('Doctor Who: Series 14 - 1. Space Babies')
            ->and($info->expectedSize())->toBe(1823.0 * 1024 * 1024);
    });

    it('falls back down the mode order and copes with modesizes', function () {
        expect(IplayerInfo::parse("qualitysizes:  original: sd=640MB,web=401MB\n")->expectedSize())->toBe(640.0 * 1024 * 1024)
            ->and(IplayerInfo::parse("modesizes:  hd=1.5GB,sd=600MB\n")->expectedSize())->toBe(1.5 * 1024 ** 3)
            ->and(IplayerInfo::parse("title: x\n")->expectedSize())->toBeNull()
            ->and(IplayerInfo::parse('')->title())->toBeNull();
    });
});

describe('status resolver', function () {
    it('ignores files that are not get_iplayer downloads', function () {
        expect(R::resolve(ipFiles(['holiday.mp4' => [5], 'notes.txt' => [1]])))->toBe([]);
        expect(R::resolve([new DirectoryEntry('Show---m001z3k1', FileType::Folder, null, 1)]))->toBe([]);
    });

    it('reports each stage of the main download', function (array $files, string $status, string $file) {
        $items = R::resolve(ipFiles($files));
        expect($items)->toHaveCount(1)
            ->and($items[0]['pid'])->toBe('m001z3k1')
            ->and($items[0]['main']['status'])->toBe($status)
            ->and($items[0]['main']['file'])->toBe($file)
            ->and($items[0]['sd'])->toBeNull()
            ->and($items[0]['nameTitle'])->toBe('Doctor Who s14e01 - Space Babies');
    })->with([
        'video' => [['Doctor_Who____s14e01_-_Space_Babies---m001z3k1.video.m4v' => [500]], R::VIDEO, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.video.m4v'],
        'hls video' => [['Doctor_Who____s14e01_-_Space_Babies---m001z3k1.hls.ts' => [500]], R::VIDEO, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.hls.ts'],
        'audio beats video' => [[
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.video.ts' => [900],
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.audio.ts' => [20],
        ], R::AUDIO, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.audio.ts'],
        'converting' => [[
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.dash.ts' => [900],
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.audio.m4a' => [20],
        ], R::ENCODING, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.dash.ts'],
        'encoding' => [[
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.partial.mp4' => [300],
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.video.m4v' => [900],
        ], R::ENCODING, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.partial.mp4'],
        'temp beats everything' => [[
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.mp4' => [900],
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1-temp.mp4' => [100],
        ], R::TEMP, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1-temp.mp4'],
        'completed (subtitles ignored)' => [[
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.mp4' => [900],
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.srt' => [9],
        ], R::COMPLETED, 'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.mp4'],
    ]);

    it('tracks the SD copy separately from the main download', function () {
        $items = R::resolve(ipFiles([
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.mp4' => [900, 2000],
            'Doctor_Who____s14e01_-_Space_Babies_(SD)---m001z3k1.video.m4v' => [100, 2100],
        ]));
        expect($items)->toHaveCount(1)
            ->and($items[0]['main']['status'])->toBe(R::COMPLETED)
            ->and($items[0]['sd']['status'])->toBe(R::VIDEO)
            ->and($items[0]['sd']['file'])->toBe('Doctor_Who____s14e01_-_Space_Babies_(SD)---m001z3k1.video.m4v')
            ->and($items[0]['nameTitle'])->toBe('Doctor Who s14e01 - Space Babies');
    });

    it('shows an SD copy even when the main file has gone', function () {
        $items = R::resolve(ipFiles(['Show____s01e01_-_Pilot_(SD)---b0abcdef.mp4' => [10]]));
        expect($items[0]['main'])->toBeNull()
            ->and($items[0]['sd']['status'])->toBe(R::COMPLETED)
            ->and($items[0]['nameTitle'])->toBe('Show s01e01 - Pilot');
    });

    it('lists several downloads, most recent activity first', function () {
        $items = R::resolve(ipFiles([
            'Old____s01e01_-_A---b0000001.mp4' => [1, 100],
            'New____s01e02_-_B---b0000002.video.m4v' => [1, 500],
            'Mid____s01e03_-_C---b0000003.mp4' => [1, 300],
        ]));
        expect(array_column($items, 'pid'))->toBe(['b0000002', 'b0000003', 'b0000001']);
    });
});
