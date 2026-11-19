<?php

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
    it('is for admin and ftp users only', function () {
        asUser('alice')->getJson('/uploads')->assertForbidden();
        asUser('bob')->getJson('/uploads')->assertOk()->assertJsonStructure(['maxBytes', 'items']);
    });

    it('saves a file, lists it, and refuses to overwrite', function () {
        $file = UploadedFile::fake()->create('Holiday Film.mp4', 12);
        asUser('bob')->post('/uploads', ['file' => $file], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertCreated()->assertJsonPath('name', 'Holiday Film.mp4');
        expect(is_file("{$this->base}/upload/Holiday Film.mp4"))->toBeTrue();

        // the get_iplayer and youtube folders, then the new file
        expect(collect(asUser('bob')->getJson('/uploads')->json('items'))->pluck('name')->all())
            ->toContain('Holiday Film.mp4')->toHaveCount(3);

        asUser('bob')->post('/uploads', ['file' => UploadedFile::fake()->create('Holiday Film.mp4', 1)], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409);
    });

    it('saves a real (non-fake) uploaded file', function () {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, str_repeat('x', 2048));
        $file = new UploadedFile($tmp, 'real.mp4', 'video/mp4', null, true);

        asUser('bob')->post('/uploads', ['file' => $file], ['X-Requested-With' => 'XMLHttpRequest'])->assertCreated();
        expect(filesize("{$this->base}/upload/real.mp4"))->toBe(2048);
    });

    it('strips paths from the client name and refuses hidden files', function () {
        asUser('bob')->post('/uploads', ['file' => UploadedFile::fake()->create('../../evil.mp4', 1)], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertCreated()->assertJsonPath('name', 'evil.mp4');
        expect(is_file("{$this->base}/upload/evil.mp4"))->toBeTrue();

        asUser('bob')->post('/uploads', ['file' => UploadedFile::fake()->create('.htaccess', 1)], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422);
    });

    it('needs the script header (CSRF) and a file', function () {
        asUser('bob')->post('/uploads', ['file' => UploadedFile::fake()->create('a.mp4', 1)], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        asUser('bob')->post('/uploads', [], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(422);
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
            "$bin '--pid' 'm001z3k1,m001z3k2' '--force' '--ffmpeg-force' '--fileprefix=<nameshort>____<senum>< - episodeshort> (SD)<---pid>' '--modes=better'",
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
            ->and($status['items'][0]['main']['status'])->toBe('video')
            ->and($status['items'][0]['title'])->toBe('Show s01e02 - Two')
            ->and($status['items'][1]['main']['status'])->toBe('stalled')
            ->and($status['items'][1]['sd']['status'])->toBe('completed');
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
