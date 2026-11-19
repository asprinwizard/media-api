<?php

use App\Admin\SmartFileNamer;

it('tidies get_iplayer names', function (string $in, string $out) {
    expect(SmartFileNamer::suggest($in))->toBe($out);
})->with([
    'episode' => ['Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4', 'S02E05 - Undertow.mp4'],
    'first episode keeps programme' => ['Flat_4B____s01e01---m0012345.mp4', 'Flat 4B - S01E01.mp4'],
    'film / one-off' => ['The_Lantern_Keeper____---m0099999.mp4', 'The Lantern Keeper.mp4'],
    'SD copy' => ['The_Lantern_Keeper____(SD)---m0099999.mp4', 'The Lantern Keeper (SD).mp4'],
    'dull "Episode N" title dropped after series 1' => ['Wild_Isles____s02e03_-_Episode_3---m001abcd.mp4', 'S02E03.mp4'],
    'programme name with regex characters' => ['Who_Wants_(To_Be)+____s01e02_-_Round_Two---m00aaaaa.mp4', 'S01E02 - Round Two.mp4'],
]);

it('strips release tags from other names', function () {
    expect(SmartFileNamer::suggest('Some.Film.2019.1080p.BluRay.x264.mkv'))->toBe('Some Film 2019.mkv');
});

it('leaves already-tidy names alone', function () {
    expect(SmartFileNamer::suggest('S01E01 - Pilot.mp4'))->toBe('S01E01 - Pilot.mp4');
});
