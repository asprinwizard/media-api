<?php

use App\Ops\DriveReport;

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = tempDir('ops');
    mkdir("{$this->base}/bin", 0777, true);
    mkdir("{$this->base}/logs", 0777, true);
    config()->set('media.tools.latest_cache_dir', "{$this->base}/latest");

    $this->script = function (string $name, string $body): string {
        $path = "{$this->base}/bin/$name";
        file_put_contents($path, "#!/bin/sh\n$body\n");
        chmod($path, 0755);

        return $path;
    };
});

it('is admin only', function () {
    foreach (['/live-tv/status', '/logs', '/logs/file_actions', '/tools/drives?server=media02', '/tools/latest?server=media02'] as $url) {
        asUser('alice')->getJson($url)->assertForbidden();
    }
    asUser('alice')->postJson('/live-tv/actions', ['action' => 'reboot'])->assertForbidden();
});

describe('live tv', function () {
    beforeEach(function () {
        config()->set('media.live_tv.host', '127.0.0.1');
        config()->set('media.live_tv.mac', 'aa:bb:cc:dd:ee:ff');
        config()->set('media.live_tv.wakeonlan', ($this->script)('wakeonlan', "echo \"\$@\" > {$this->base}/wol"));
    });

    it('reports offline, then online once something answers', function () {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server); // nothing listening now
        config()->set('media.live_tv.check_port', $port);
        asUser('richard')->getJson('/live-tv/status')->assertOk()
            ->assertJson(['online' => false, 'canWake' => true, 'hls' => '/hls/stream.m3u8']);

        $server = stream_socket_server("tcp://127.0.0.1:$port");
        asUser('richard')->getJson('/live-tv/status')->assertJsonPath('online', true);
        fclose($server);
    });

    it('wakes the box with its MAC address', function () {
        asUser('richard')->postJson('/live-tv/actions', ['action' => 'wake'])->assertOk();
        expect(trim(file_get_contents("{$this->base}/wol")))->toBe('aa:bb:cc:dd:ee:ff');
    });

    it('says when Wake-on-LAN is not set up', function () {
        config()->set('media.live_tv.mac', null);
        asUser('richard')->getJson('/live-tv/status')->assertJsonPath('canWake', false);
        asUser('richard')->postJson('/live-tv/actions', ['action' => 'wake'])->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'STREAMER_MAC_ADDRESS'));
    });

    it('sends fixed power commands over ssh, and accepts the connection dropping', function () {
        config()->set('media.media01.ssh', ($this->script)('ssh22', "echo \"\$@\" > {$this->base}/ssh; echo 'Connection to media08 closed by remote host.' >&2; exit 255"));
        asUser('richard')->postJson('/live-tv/actions', ['action' => 'reboot'])->assertOk();
        expect(file_get_contents("{$this->base}/ssh"))->toContain('media08@127.0.0.1')->toContain("'sudo' 'reboot'");

        asUser('richard')->postJson('/live-tv/actions', ['action' => 'rm -rf /'])->assertStatus(422);
    });

    it('fails when the box cannot be reached at all', function () {
        config()->set('media.media01.ssh', ($this->script)('ssh22', "echo 'ssh: connect to host media08 port 22: No route to host' >&2; exit 255"));
        asUser('richard')->postJson('/live-tv/actions', ['action' => 'sleep'])->assertStatus(502);
    });
});

describe('logs', function () {
    beforeEach(function () {
        file_put_contents("{$this->base}/logs/a.log", implode("\n", array_map(fn ($i) => "line $i", range(1, 50)))."\n");
        file_put_contents("{$this->base}/logs/big.log", str_repeat("0123456789abcdef\n", 200_000)); // ~3.4 MB
        config()->set('media.logs', [
            'a' => ['Log A', "{$this->base}/logs/a.log"],
            'big' => ['Big', "{$this->base}/logs/big.log"],
            'gone' => ['Missing', "{$this->base}/logs/nope.log"],
        ]);
    });

    it('lists the configured logs only', function () {
        asUser('richard')->getJson('/logs')->assertOk()
            ->assertJsonPath('logs.0', ['key' => 'a', 'title' => 'Log A', 'exists' => true, 'size' => 391, 'mtime' => filemtime("{$this->base}/logs/a.log")])
            ->assertJsonPath('logs.2.exists', false);
    });

    it('tails the last lines', function () {
        asUser('richard')->getJson('/logs/a?lines=3')->assertOk()->assertJsonPath('text', "line 48\nline 49\nline 50\n");
    });

    it('shows the whole file, or the end of a big one from a whole line', function () {
        expect(asUser('richard')->getJson('/logs/a')->json('text'))->toStartWith("line 1\n");
        $big = asUser('richard')->getJson('/logs/big')->assertJsonPath('truncated', true)->json('text');
        expect(strlen($big))->toBeLessThanOrEqual(2 * 1024 * 1024)->and($big)->toStartWith('0123456789abcdef');
    });

    it('refuses anything not in the list', function () {
        asUser('richard')->getJson('/logs/passwd')->assertNotFound();
        asUser('richard')->getJson('/logs/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        asUser('richard')->getJson('/logs/gone')->assertOk()->assertJsonPath('text', '');
    });
});

describe('tools', function () {
    it('parses df, drive states and folder links', function () {
        $out = "##DF\nFilesystem 1-blocks Used Available Capacity Mounted on\n"
            ."/dev/root 62578692096 53433909248 6570176512 90% /\n"
            ."tmpfs 1987579904 0 1987579904 0% /dev/shm\n"
            ."/dev/loop2 48234496 48234496 0 100% /snap/core18/3086\n"
            ."/dev/sdc2 5000928423936 4600000000000 400000000000 92% /mnt/hd07\n"
            ."mergerFS 26953591390208 24494609334272 2458982055936 91% /home/share/Video Disk\n"
            ."##DRIVES\n/dev/sda\tactive/idle\n/dev/sdb\tstandby\n/dev/sdc\t\n"
            ."##LINKS\nMovies\tlink\t/mnt/hd02/Movies\nTV/Music\tmissing\t\n";

        $r = DriveReport::parse($out);
        expect(array_column($r['space'], 'mount'))->toBe(['/', '/home/share/Video Disk', '/mnt/hd07'])
            ->and($r['space'][2])->toMatchArray(['filesystem' => '/dev/sdc2', 'percent' => 92, 'size' => 5000928423936.0])
            ->and($r['drives'])->toBe([
                ['device' => '/dev/sda', 'state' => 'active/idle'],
                ['device' => '/dev/sdb', 'state' => 'standby'],
                ['device' => '/dev/sdc', 'state' => 'unknown'],
            ])
            ->and($r['links'])->toBe([
                ['folder' => 'Movies', 'kind' => 'link', 'target' => '/mnt/hd02/Movies'],
                ['folder' => 'TV/Music', 'kind' => 'missing', 'target' => null],
            ]);
    });

    it('reports drives on media02 and media01 with the real script', function () {
        $base = makeLibrary();
        $m1 = fakeMedia01($base);
        mkdir("$m1/Movies", 0777, true);
        config()->set('media.tools.hdparm', ($this->script)('hdparm', 'printf "\n%s:\n drive state is:  standby\n" "$2"'));
        config()->set('media.tools.hdparm_sudo', false);
        config()->set('media.tools.media01_hdparm', "{$this->base}/bin/hdparm");

        $m2 = asUser('richard')->getJson('/tools/drives?server=media02')->assertOk()->json();
        expect($m2['space'])->not->toBeEmpty()
            ->and(collect($m2['links'])->firstWhere('folder', 'Movies'))->toMatchArray(['kind' => 'link', 'target' => realpath("$base/drives/hd02/Movies")]);

        $r1 = asUser('richard')->getJson('/tools/drives?server=media01')->assertOk()->json();
        expect($r1['error'])->toBeNull()
            ->and(collect($r1['links'])->firstWhere('folder', 'Movies')['kind'])->toBe('dir')
            ->and(collect($r1['links'])->firstWhere('folder', 'TV/Drama')['kind'])->toBe('missing');

        fakeMedia01($base, reachable: false);
        asUser('richard')->getJson('/tools/drives?server=media01')->assertOk()->assertJsonPath('error', "media01 can't be reached right now.");
    });

    it('lists the latest files newest first, in pages, from a cached scan', function () {
        $base = makeLibrary();
        $lib = config('media.paths.library');
        @mkdir("$lib/TV/New", 0777, true);
        touch("$lib/TV/New/newest.mp4", time() + 100);
        touch("$lib/TV/New/older.mkv", time() + 50);

        $page = asUser('richard')->getJson('/tools/latest?server=media02')->assertOk()->json();
        expect(array_slice(array_column($page['items'], 'path'), 0, 2))->toBe(['TV/New/newest.mp4', 'TV/New/older.mkv'])
            ->and($page['items'][0])->toMatchArray(['name' => 'newest.mp4', 'folder' => 'TV/New', 'type' => 'video']);

        // Reused until refreshed.
        touch("$lib/TV/New/brand-new.mp4", time() + 200);
        expect(asUser('richard')->getJson('/tools/latest?server=media02')->json('items.0.name'))->toBe('newest.mp4');
        expect(asUser('richard')->getJson('/tools/latest?server=media02&refresh=1')->json('items.0.name'))->toBe('brand-new.mp4');
    });

    it('scans media01 too', function () {
        $base = makeLibrary();
        $m1 = fakeMedia01($base);
        mkdir("$m1/Movies/Film", 0777, true);
        touch("$m1/Movies/Film/Film (2020).mp4");
        asUser('richard')->getJson('/tools/latest?server=media01&refresh=1')->assertOk()
            ->assertJsonPath('items.0.path', 'Movies/Film/Film (2020).mp4')->assertJsonPath('more', false);
    });
});
