<?php

use App\Acquire\Youtube\YoutubeService as Y;

describe('links', function () {
    it('normalises video links, recognises playlists and rejects the rest', function (string $url, string $kind, ?string $normalised) {
        expect(Y::classify($url))->toBe(['kind' => $kind, 'url' => $normalised]);
    })->with([
        ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123&t=42', 'video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['https://m.youtube.com/shorts/dQw4w9WgXcQ', 'video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', 'playlist', 'https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG'],
        ['https://www.youtube.com/watch?v=$(id)', 'invalid', null],
        ['https://evil.example/watch?v=dQw4w9WgXcQ', 'invalid', null],
        ['https://notyoutube.com/watch?v=dQw4w9WgXcQ', 'invalid', null],
        ['file:///etc/passwd', 'invalid', null],
        ['--exec=id', 'invalid', null],
    ]);
});

describe('formats', function () {
    it('splits video and audio, best first, and skips storyboards', function () {
        $formats = Y::formats(json_decode(file_get_contents(base_path('tests/fixtures/ytdlp/video.json')), true)['formats']);
        expect(array_column($formats['video'], 'id'))->toBe(['137', '136', '18'])
            ->and(array_column($formats['audio'], 'id'))->toBe(['251', '140'])
            ->and($formats['video'][2]['hasAudio'])->toBeTrue()
            ->and($formats['video'][0]['hasAudio'])->toBeFalse()
            ->and($formats['audio'][0]['approximate'])->toBeTrue()
            ->and($formats['audio'][0]['resolution'])->toBe('audio only');
    });
});

describe('download log', function () {
    it('is downloading while there is no finished marker', function () {
        $status = Y::parseLog("[youtube] Extracting URL\n[download] Destination: /x/Chan/Test.f137.mp4\n[download]  42.3% of ~ 1.20GiB at 3.4MiB/s ETA 00:21\n");
        expect($status)->toMatchArray(['active' => true, 'state' => 'downloading', 'percent' => 42.3, 'destination' => 'Test.f137.mp4']);
    });

    it('tracks playlist items', function () {
        $status = Y::parseLog("[download] Downloading item 3 of 12\n[download]   5.0% of 10MiB\n");
        expect($status['item'])->toBe('3 of 12')->and($status['percent'])->toBe(5.0);
    });

    it('finishes on exit code 0, fails otherwise with the error', function () {
        expect(Y::parseLog("[Merger] Merging formats into \"/x/Chan/Test.mp4\"\n".Y::FINISHED_MARKER."0\n"))
            ->toMatchArray(['active' => false, 'state' => 'finished', 'destination' => 'Test.mp4', 'error' => null]);
        expect(Y::parseLog("ERROR: [youtube] abc: Video unavailable\n".Y::FINISHED_MARKER."1\n"))
            ->toMatchArray(['active' => false, 'state' => 'failed', 'error' => '[youtube] abc: Video unavailable']);
    });

    it('treats an old log without a marker as stopped', function () {
        expect(Y::parseLog("[download]  10.0% of 1GiB\n", stale: true))
            ->toMatchArray(['active' => false, 'state' => 'failed', 'error' => 'The download stopped without finishing.']);
    });
});
