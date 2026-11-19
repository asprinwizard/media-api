<?php

use App\Acquire\Iplayer\IplayerService;
use App\Acquire\Youtube\YoutubeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    useHtgroup("admin: richard\nftp: bob\nstandard: alice bob richard\n");
    $this->base = tempDir('acquire');
    foreach (['upload/get_iplayer', 'upload/youtube', 'public/get_iplayer', 'logs', 'bin'] as $dir) {
        mkdir("{$this->base}/$dir", 0777, true);
    }
    config()->set('media.paths.upload', "{$this->base}/upload");
    config()->set('media.iplayer.download_dir', "{$this->base}/upload/get_iplayer");
    config()->set('media.iplayer.public_dir', "{$this->base}/public/get_iplayer");
    config()->set('media.iplayer.logs', [
        'search' => "{$this->base}/logs/search.log",
        'download' => "{$this->base}/logs/download.log",
        'info' => "{$this->base}/logs/info.log",
    ]);

    // A stand-in get-iplayer: search prints the fixture, --info prints the
    // fileinfo fixture, --pid "downloads" an .mp4 per PID and logs its argv.
    $fixtures = base_path('tests/fixtures/iplayer');
    $dl = "{$this->base}/upload/get_iplayer";
    file_put_contents("{$this->base}/bin/get-iplayer", <<<SH
        #!/bin/sh
        for a in "\$@"; do printf '%s\\n' "\$a"; done >> "{$this->base}/logs/argv"
        echo "---" >> "{$this->base}/logs/argv"
        case "\$1" in
          --) cat "$fixtures/search.txt" ;;
          --info) cat "$fixtures/fileinfo.txt" ;;
          --pid) for p in \$(echo "\$2" | tr ',' ' '); do : > "$dl/Show____s01e01_-_Ep---\$p.mp4"; done ;;
        esac
        SH);
    chmod("{$this->base}/bin/get-iplayer", 0755);
    config()->set('media.iplayer.command', ["{$this->base}/bin/get-iplayer"]);

    config()->set('media.youtube.download_dir', "{$this->base}/upload/youtube");
    config()->set('media.youtube.log', "{$this->base}/logs/yt.log");
    config()->set('media.youtube.cookies', "{$this->base}/nope.txt");
});

function waitFor(callable $condition, float $seconds = 5): bool
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        if ($condition()) {
            return true;
        }
        usleep(50_000);
    }

    return $condition();
}

describe('upload', function () {
    beforeEach(function () {
        config()->set('media.upload.chunks_dir', "{$this->base}/chunks");
        $this->xhr = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->send = function (string $user, string $id, int $index, string $bytes) {
            $tmp = tempnam(sys_get_temp_dir(), 'chunk');
            file_put_contents($tmp, $bytes);

            return asUser($user)->post("/uploads/$id/chunks?index=$index", ['chunk' => new UploadedFile($tmp, 'blob', null, null, true)], $this->xhr);
        };
    });

    it('is for admin and ftp users only', function () {
        asUser('alice')->getJson('/uploads')->assertForbidden();
        asUser('alice')->postJson('/uploads', ['name' => 'a.mp4', 'size' => 1])->assertForbidden();
        asUser('bob')->getJson('/uploads')->assertOk()->assertJsonStructure(['chunkBytes', 'items']);
    });

    it('assembles a file from chunks and moves it into the upload folder', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'Holiday Film.mp4', 'size' => 10])
            ->assertCreated()->assertJsonPath('name', 'Holiday Film.mp4')->json('id');

        ($this->send)('bob', $id, 0, 'abcd')->assertOk()->assertJsonPath('received', 1);
        ($this->send)('bob', $id, 1, 'efgh')->assertOk()->assertJsonPath('received', 2);
        ($this->send)('bob', $id, 2, 'ij')->assertOk()->assertJsonPath('bytes', 10);
        asUser('bob')->postJson("/uploads/$id/finish")->assertCreated()->assertJsonPath('name', 'Holiday Film.mp4');

        expect(file_get_contents("{$this->base}/upload/Holiday Film.mp4"))->toBe('abcdefghij')
            ->and(is_dir("{$this->base}/chunks/$id"))->toBeFalse();
        expect(collect(asUser('bob')->getJson('/uploads')->json('items'))->pluck('name'))->toContain('Holiday Film.mp4');
    });

    it('accepts a retried chunk once, and refuses chunks out of order', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'a.mp4', 'size' => 6])->json('id');
        ($this->send)('bob', $id, 0, 'abc')->assertOk();
        ($this->send)('bob', $id, 0, 'abc')->assertOk()->assertJsonPath('received', 1); // retry: not appended twice
        ($this->send)('bob', $id, 2, 'zzz')->assertStatus(409)->assertJsonPath('expected', 1);
        asUser('bob')->postJson("/uploads/$id/finish")->assertStatus(409)->assertJsonPath('expected', 1);
        ($this->send)('bob', $id, 1, 'def')->assertOk();
        asUser('bob')->postJson("/uploads/$id/finish")->assertCreated();
        expect(file_get_contents("{$this->base}/upload/a.mp4"))->toBe('abcdef');
    });

    it('trims a half-written chunk before appending again', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'b.mp4', 'size' => 6])->json('id');
        ($this->send)('bob', $id, 0, 'abc')->assertOk();
        file_put_contents("{$this->base}/chunks/$id/data", 'de', FILE_APPEND); // an attempt that died part-way
        ($this->send)('bob', $id, 1, 'def')->assertOk();
        asUser('bob')->postJson("/uploads/$id/finish")->assertCreated();
        expect(file_get_contents("{$this->base}/upload/b.mp4"))->toBe('abcdef');
    });

    it('handles files over 2 GB (sizes never pass through a PHP int)', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'big.mkv', 'size' => 3_000_000_003])->json('id');
        // Pretend 3 GB already arrived (a sparse file, so no disk is used).
        exec('truncate -s 3000000000 '.escapeshellarg("{$this->base}/chunks/$id/data"));
        $state = json_decode(file_get_contents("{$this->base}/chunks/$id/state.json"), true);
        file_put_contents("{$this->base}/chunks/$id/state.json", json_encode(['received' => 90, 'bytes' => 3_000_000_000.0] + $state, JSON_PRESERVE_ZERO_FRACTION));

        ($this->send)('bob', $id, 90, 'abc')->assertOk()->assertJsonPath('received', 91);
        asUser('bob')->postJson("/uploads/$id/finish")->assertCreated();
        expect(trim(exec('stat -c %s '.escapeshellarg("{$this->base}/upload/big.mkv"))))->toBe('3000000003');
    });

    it('refuses more data than declared', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'c.mp4', 'size' => 2])->json('id');
        ($this->send)('bob', $id, 0, 'abcdef')->assertStatus(422);
        expect(filesize("{$this->base}/chunks/$id/data"))->toBe(0);
    });

    it('refuses existing names, odd names, and other people\'s uploads', function () {
        file_put_contents("{$this->base}/upload/taken.mp4", 'x');
        asUser('bob')->postJson('/uploads', ['name' => 'taken.mp4', 'size' => 1])->assertStatus(409);
        asUser('bob')->postJson('/uploads', ['name' => '.htaccess', 'size' => 1])->assertStatus(422);
        asUser('bob')->postJson('/uploads', ['name' => '../../evil.mp4', 'size' => 1])->assertCreated()->assertJsonPath('name', 'evil.mp4');

        $id = asUser('bob')->postJson('/uploads', ['name' => 'mine.mp4', 'size' => 1])->json('id');
        ($this->send)('richard', $id, 0, 'x')->assertForbidden();
        asUser('bob')->postJson('/uploads/../../etc/finish')->assertNotFound();
        asUser('bob')->postJson('/uploads/0123456789abcdef01234567/finish')->assertNotFound();
    });

    it('cancels, and clears out abandoned uploads', function () {
        $id = asUser('bob')->postJson('/uploads', ['name' => 'd.mp4', 'size' => 5])->json('id');
        asUser('bob')->deleteJson("/uploads/$id")->assertNoContent();
        expect(is_dir("{$this->base}/chunks/$id"))->toBeFalse();

        $old = asUser('bob')->postJson('/uploads', ['name' => 'e.mp4', 'size' => 5])->json('id');
        touch("{$this->base}/chunks/$old/state.json", time() - 2 * 86400);
        asUser('bob')->postJson('/uploads', ['name' => 'f.mp4', 'size' => 5])->assertCreated();
        expect(is_dir("{$this->base}/chunks/$old"))->toBeFalse();
    });

    it('needs the script header (CSRF)', function () {
        asUser('bob')->post('/uploads', ['name' => 'a.mp4', 'size' => 1], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    });
});

describe('iplayer', function () {
    it('is admin only', function () {
        asUser('bob')->getJson('/iplayer/status')->assertForbidden();
        asUser('bob')->postJson('/iplayer/downloads', ['pids' => ['m001z3k1']])->assertForbidden();
    });

    it('searches, passing the keywords as one argument after --', function () {
        asUser('richard')->getJson('/iplayer/search?q='.urlencode('doctor who; rm -rf /'))
            ->assertOk()->assertJsonCount(3, 'results')->assertJsonPath('results.1.pid', 'm001z3k2');

        expect(file_get_contents("{$this->base}/logs/argv"))->toBe("--\ndoctor who; rm -rf /\n---\n")
            ->and(file_get_contents("{$this->base}/logs/search.log"))->toContain('Matches:');
    });

    it('rejects anything that is not a PID or iPlayer link', function () {
        asUser('richard')->postJson('/iplayer/downloads', ['pids' => ['m001z3k1 --exec=id']])->assertStatus(422);
        expect(file_exists("{$this->base}/public/get_iplayer/download_queue"))->toBeFalse();
    });

    it('queues safely quoted commands, appending rather than overwriting', function () {
        Process::fake();
        asUser('richard')->postJson('/iplayer/downloads', ['pids' => ['m001z3k1', 'M001Z3K2'], 'sdCopy' => true])->assertStatus(202);
        asUser('richard')->postJson('/iplayer/downloads', ['pids' => ['https://www.bbc.co.uk/iplayer/episode/b006m86d/x'], 'force' => true, 'recursive' => true])
            ->assertStatus(202)->assertJsonCount(3, 'queue.pending');

        $bin = escapeshellarg("{$this->base}/bin/get-iplayer");
        expect(file("{$this->base}/public/get_iplayer/download_queue", FILE_IGNORE_NEW_LINES))->toBe([
            "$bin '--pid' 'm001z3k1,m001z3k2'",
            "$bin '--pid' 'm001z3k1,m001z3k2' '--force' '--ffmpeg-force' '--fileprefix=<nameshort>____<senum>< - episodeshort> (SD)<---pid>' '--quality=sd'",
            "$bin '--pid' 'b006m86d' '--force' '--ffmpeg-force' '--pid-recursive'",
        ]);
        Process::assertRan(fn ($p) => str_contains(is_array($p->command) ? implode(' ', $p->command) : $p->command, 'get_iplayer_download_queue'));
    });

    it('runs the queue end to end and reports the downloads', function () {
        asUser('richard')->postJson('/iplayer/downloads', ['pids' => ['m001z3k1']])->assertStatus(202);

        $done = waitFor(fn () => is_file("{$this->base}/upload/get_iplayer/Show____s01e01_-_Ep---m001z3k1.mp4")
            && ! file_exists("{$this->base}/public/get_iplayer/download_queue_active"));
        expect($done)->toBeTrue();
        expect(file_get_contents("{$this->base}/logs/download.log"))->toContain('iPlayer download process completed');

        // First status check starts an info lookup; once it lands the title and size appear.
        asUser('richard')->getJson('/iplayer/status')->assertOk()
            ->assertJsonPath('items.0.pid', 'm001z3k1')
            ->assertJsonPath('items.0.main.status', 'completed');
        expect(waitFor(fn () => is_file("{$this->base}/public/get_iplayer/m001z3k1/fileinfo")))->toBeTrue();

        asUser('richard')->getJson('/iplayer/status')
            ->assertJsonPath('active', false)
            ->assertJsonPath('items.0.title', 'Doctor Who: Series 14 - 1. Space Babies')
            ->assertJsonPath('items.0.main.label', 'Completed');
    });

    it('reports an abandoned file as stalled even while the queue is busy', function () {
        touch("{$this->base}/upload/get_iplayer/Old____s01e01_-_A---b0000009.partial.mp4", time() - 7200);
        mkdir("{$this->base}/public/get_iplayer/b0000009");
        touch("{$this->base}/public/get_iplayer/b0000009/fileinfo.fetching");
        file_put_contents("{$this->base}/public/get_iplayer/download_queue_active", "get-iplayer --pid b0000010\n");

        asUser('richard')->getJson('/iplayer/status')
            ->assertJsonPath('active', true)
            ->assertJsonPath('items.0.main.status', 'stalled');
    });

    it('reports in-progress, stalled and SD-copy tracks', function () {
        $dir = "{$this->base}/upload/get_iplayer";
        touch("$dir/Show____s01e02_-_Two---b0000002.video.m4v");
        touch("$dir/Show____s01e01_-_One_(SD)---b0000001.mp4", time() - 7200);
        touch("$dir/Show____s01e01_-_One---b0000001.partial.mp4", time() - 7200);
        mkdir("{$this->base}/public/get_iplayer/b0000001");
        mkdir("{$this->base}/public/get_iplayer/b0000002");
        touch("{$this->base}/public/get_iplayer/b0000001/fileinfo.fetching"); // pretend lookups are under way
        touch("{$this->base}/public/get_iplayer/b0000002/fileinfo.fetching");

        $status = asUser('richard')->getJson('/iplayer/status')->assertOk()->json();
        expect($status['active'])->toBeTrue()
            ->and($status['items'][0]['pid'])->toBe('b0000002')
            ->and($status['items'][0]['main']['status'])->toBe('downloading')
            ->and($status['items'][0]['title'])->toBe('Show s01e02 - Two')
            ->and($status['items'][1]['main']['status'])->toBe('stalled')
            ->and($status['items'][1]['sd']['status'])->toBe('completed');
    });

    it('measures conversion against the source, and spots the finishing pass', function () {
        $dir = "{$this->base}/upload/get_iplayer";
        $sized = function (string $file, int $bytes) {
            $h = fopen($file, 'w');
            ftruncate($h, $bytes);
            fclose($h);
        };
        $sized("$dir/Show____s01e01_-_One---b0000001.hls.ts", 1800);
        $sized("$dir/Show____s01e01_-_One---b0000001.partial.mp4", 1700);
        mkdir("{$this->base}/public/get_iplayer/b0000001");
        touch("{$this->base}/public/get_iplayer/b0000001/fileinfo.fetching");
        $service = app(IplayerService::class);
        $now = time();

        $main = fn (int $at) => $service->status($at)['items'][0]['main'];
        expect($main($now))->toMatchArray(['status' => 'converting', 'label' => 'Converting to MP4', 'size' => 1700.0, 'expectedSize' => 1800.0]);
        // Same size 20 s later while ffmpeg is still writing: the finishing pass.
        expect($main($now + 20))->toMatchArray(['status' => 'finishing', 'label' => 'Finishing the MP4', 'expectedSize' => null]);

        // Growing again (a new conversion) resets it.
        $sized("$dir/Show____s01e01_-_One---b0000001.partial.mp4", 1750);
        expect($main($now + 22)['status'])->toBe('converting');

        // Renamed to .mp4 before the cover art appears: not completed yet...
        unlink("$dir/Show____s01e01_-_One---b0000001.partial.mp4");
        unlink("$dir/Show____s01e01_-_One---b0000001.hls.ts");
        $sized("$dir/Show____s01e01_-_One---b0000001.mp4", 1750);
        touch("$dir/Show____s01e01_-_One---b0000001.mp4", $now + 23);
        expect($main($now + 24)['status'])->toBe('tagging');

        // Tagging: the temp copy is measured against the finished .mp4.
        $sized("$dir/Show____s01e01_-_One---b0000001-temp-123.mp4", 500);
        expect($main($now + 30))->toMatchArray(['status' => 'tagging', 'label' => 'Adding tags and artwork', 'size' => 500.0, 'expectedSize' => 1750.0]);

        // Tagged copy renamed over the .mp4: tagging for a few seconds more, then completed for good.
        unlink("$dir/Show____s01e01_-_One---b0000001-temp-123.mp4");
        touch("$dir/Show____s01e01_-_One---b0000001.mp4", $now + 32);
        expect($main($now + 33)['status'])->toBe('tagging')
            ->and($main($now + 43)['status'])->toBe('completed')
            ->and($main($now + 44)['status'])->toBe('completed');
    });
});

describe('youtube', function () {
    beforeEach(function () {
        $fixture = base_path('tests/fixtures/ytdlp/video.json');
        file_put_contents("{$this->base}/bin/yt-dlp", <<<SH
            #!/bin/sh
            for a in "\$@"; do printf '%s\\n' "\$a"; done > "{$this->base}/logs/yt-argv"
            if [ "\$1" = "-J" ]; then cat "$fixture"; exit 0; fi
            echo "[download] Destination: {$this->base}/upload/youtube/Chan/Test.mp4"
            echo "[download] 100% of 10.00MiB"
            SH);
        chmod("{$this->base}/bin/yt-dlp", 0755);
        config()->set('media.youtube.binary', "{$this->base}/bin/yt-dlp");
    });

    it('describes a video with its formats, and playlists without', function () {
        asUser('richard')->getJson('/youtube/info?url='.urlencode('https://youtu.be/dQw4w9WgXcQ?t=3'))
            ->assertOk()->assertJsonPath('kind', 'video')->assertJsonPath('title', 'Test Video')
            ->assertJsonPath('url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('formats.video.0.id', '137');
        expect(file("{$this->base}/logs/yt-argv", FILE_IGNORE_NEW_LINES))->toBe(['-J', '--no-playlist', '--no-warnings', '--', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        asUser('richard')->getJson('/youtube/info?url='.urlencode('https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG'))
            ->assertOk()->assertJsonPath('kind', 'playlist')->assertJsonPath('formats', null);
        asUser('richard')->getJson('/youtube/info?url=nonsense')->assertOk()->assertJsonPath('kind', 'invalid');
        asUser('bob')->getJson('/youtube/info?url=nonsense')->assertForbidden();
    });

    it('downloads in the background, reports progress, and allows one at a time', function () {
        asUser('richard')->postJson('/youtube/downloads', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'video' => '137', 'audio' => '140'])->assertStatus(202);
        expect(waitFor(fn () => str_contains((string) @file_get_contents("{$this->base}/logs/yt.log"), YoutubeService::FINISHED_MARKER)))->toBeTrue();

        expect(file("{$this->base}/logs/yt-argv", FILE_IGNORE_NEW_LINES))->toBe([
            '--newline', '-f', '137+140', '--no-playlist', '-o', "{$this->base}/upload/youtube/%(channel)s/%(title)s.%(ext)s", '--', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
        asUser('richard')->getJson('/youtube/status')->assertOk()
            ->assertJsonPath('state', 'finished')->assertJsonPath('destination', 'Test.mp4')->assertJsonPath('active', false);

        file_put_contents("{$this->base}/logs/yt.log", "[download]  10.0% of 1GiB\n");
        asUser('richard')->postJson('/youtube/downloads', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertStatus(409);
    });

    it('rejects bad links and format codes', function () {
        asUser('richard')->postJson('/youtube/downloads', ['url' => 'https://evil.example/x'])->assertStatus(422);
        asUser('richard')->postJson('/youtube/downloads', ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'video' => '137; id'])->assertStatus(422);
    });
});
