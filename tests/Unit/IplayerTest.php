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

    it('reads real get_iplayer 3.36 output', function () {
        $results = IplayerService::parseSearch(file_get_contents(base_path('tests/fixtures/iplayer/search-real.txt')));
        expect($results)->toHaveCount(7)
            ->and($results[0])->toBe(['index' => '268', 'label' => 'BBC London: Evening News - 21/10/2026, BBC One, m00334px', 'pid' => 'm00334px'])
            ->and($results[6]['pid'])->toBe('m0034ggg');
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
            ->and($info->expectedSize())->toBe(1823.0e6);
    });

    it('reads a real fileinfo, with the SD size for SD copies', function () {
        $info = IplayerInfo::parse(file_get_contents(base_path('tests/fixtures/iplayer/fileinfo-real.txt')));
        expect($info->title())->toBe('In the Night Garden: Series 1: The Pontipines on the Ninky Nonk')
            ->and($info->expectedSize())->toBe(1819.0e6)
            ->and($info->expectedSize(sdCopy: true))->toBe(594.0e6);
    });

    it('falls back down the mode order and copes with modesizes', function () {
        expect(IplayerInfo::parse("qualitysizes:  original: sd=640MB,web=401MB\n")->expectedSize())->toBe(640.0e6)
            ->and(IplayerInfo::parse("modesizes:  hd=1.5GB,sd=600MB\n")->expectedSize())->toBe(1.5e9)
            ->and(IplayerInfo::parse("qualitysizes:  original: fhd=2GB,sd=640MB\n")->expectedSize(sdCopy: true))->toBe(640.0e6)
            ->and(IplayerInfo::parse("title: x\n")->expectedSize())->toBeNull()
            ->and(IplayerInfo::parse('')->title())->toBeNull();
    });
});

describe('status resolver', function () {
    it('ignores files that are not get_iplayer downloads', function () {
        expect(R::resolve(ipFiles(['holiday.mp4' => [5], 'notes.txt' => [1]])))->toBe([]);
        expect(R::resolve([new DirectoryEntry('Show---m001z3k1', FileType::Folder, null, 1)]))->toBe([]);
    });

    // The sequence a real download went through on media02 (get_iplayer 3.36, Nov 2026).
    it('follows a real download through each stage', function (array $files, string $status, string $file, ?float $basis) {
        $items = R::resolve(ipFiles($files));
        expect($items)->toHaveCount(1)
            ->and($items[0]['pid'])->toBe('b00796ry')
            ->and($items[0]['main']['status'])->toBe($status)
            ->and($items[0]['main']['file'])->toBe($file)
            ->and($items[0]['main']['basis'])->toBe($basis)
            ->and($items[0]['sd'])->toBeNull()
            ->and($items[0]['nameTitle'])->toBe('In the Night Garden s01e34 - The Pontipines on the Ninky Nonk');
    })->with([
        'downloading' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.video.ts' => [1313753024],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.video.txt' => [5614],
        ], R::DOWNLOADING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.video.ts', null],
        'renamed, about to convert' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.hls.ts' => [1807674144],
        ], R::CONVERTING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.hls.ts', 1807674144.0],
        'converting' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.hls.ts' => [1807674144],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.partial.mp4' => [940298240],
        ], R::CONVERTING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.partial.mp4', 1807674144.0],
        'renamed, source not yet deleted' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.hls.ts' => [1807674144],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4' => [1758921096],
        ], R::TAGGING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4', null],
        'cover art saved' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.jpg' => [122880],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4' => [1758921096],
        ], R::TAGGING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4', null],
        'writing the tagged copy' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry-temp-61318.mp4' => [77352960],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.jpg' => [589141],
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4' => [1758921096],
        ], R::TAGGING, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry-temp-61318.mp4', 1758921096.0],
        'completed' => [[
            'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4' => [1758921096],
        ], R::COMPLETED, 'In_the_Night_Garden____s01e34_-_The_Pontipines_on_the_Ninky_Nonk---b00796ry.mp4', null],
    ]);

    it('still recognises the older separate audio and video files', function (array $files, string $status) {
        expect(R::resolve(ipFiles($files))[0]['main']['status'])->toBe($status);
    })->with([
        'video' => [['Show____s01e01_-_A---m001z3k1.video.m4v' => [500]], R::DOWNLOADING],
        'audio beats video' => [['Show____s01e01_-_A---m001z3k1.video.m4v' => [900], 'Show____s01e01_-_A---m001z3k1.audio.m4a' => [20]], R::AUDIO],
        'dash conversion' => [['Show____s01e01_-_A---m001z3k1.dash.ts' => [900], 'Show____s01e01_-_A---m001z3k1.audio.m4a' => [20]], R::CONVERTING],
        'subtitles ignored' => [['Show____s01e01_-_A---m001z3k1.mp4' => [900], 'Show____s01e01_-_A---m001z3k1.srt' => [9]], R::COMPLETED],
    ]);

    it('tracks the SD copy separately from the main download', function () {
        $items = R::resolve(ipFiles([
            'Doctor_Who____s14e01_-_Space_Babies---m001z3k1.mp4' => [900, 2000],
            'Doctor_Who____s14e01_-_Space_Babies_(SD)---m001z3k1.video.m4v' => [100, 2100],
        ]));
        expect($items)->toHaveCount(1)
            ->and($items[0]['main']['status'])->toBe(R::COMPLETED)
            ->and($items[0]['sd']['status'])->toBe(R::DOWNLOADING)
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
