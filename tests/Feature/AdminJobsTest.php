<?php

use App\Admin\Convert\Converter;
use App\Admin\Extract\ArchiveExtractor;
use App\Admin\Extract\ExtractionFailed;
use App\Admin\Extract\ExtractJobs;
use App\Support\Shell\LocalShell;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    useHtgroup("admin: richard\nstandard: alice richard\n");
    $this->base = makeLibrary();
    $this->show = "{$this->base}/share/Video/TV/Drama/Show";
    config()->set('media.extract.jobs_path', "{$this->base}/jobs");
});

function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
}

describe('extract', function () {
    it('extracts zip and tar.gz without unar', function () {
        makeZip("{$this->show}/pack.zip", ['a.mp4' => 'A', 'sub/b.srt' => 'B']);
        $extractor = new ArchiveExtractor(new LocalShell, 60, unarBinary: 'definitely-not-installed-unar');

        $extractor->extract("{$this->show}/pack.zip", "{$this->show}/out");
        expect(file_get_contents("{$this->show}/out/sub/b.srt"))->toBe('B');

        mkdir("{$this->base}/tarsrc");
        file_put_contents("{$this->base}/tarsrc/c.mkv", 'C');
        exec('tar -czf '.escapeshellarg("{$this->show}/pack.tar.gz").' -C '.escapeshellarg("{$this->base}/tarsrc").' c.mkv');
        $extractor->extract("{$this->show}/pack.tar.gz", "{$this->show}/out2");
        expect(file_get_contents("{$this->show}/out2/c.mkv"))->toBe('C');
    });

    it('refuses zips with entries that escape the destination', function () {
        makeZip("{$this->show}/evil.zip", ['../../escaped.txt' => 'x', 'ok.txt' => 'y']);
        $extractor = new ArchiveExtractor(new LocalShell, 60, unarBinary: 'definitely-not-installed-unar');

        expect(fn () => $extractor->extract("{$this->show}/evil.zip", "{$this->show}/out"))
            ->toThrow(ExtractionFailed::class, 'unsafe path');
        expect(file_exists("{$this->base}/share/Video/TV/escaped.txt"))->toBeFalse()
            ->and(file_exists("{$this->show}/out/ok.txt"))->toBeFalse();
    });

    it('says rar needs unar when it is missing', function () {
        file_put_contents("{$this->show}/x.rar", 'Rar!');
        $extractor = new ArchiveExtractor(new LocalShell, 60, unarBinary: 'definitely-not-installed-unar');

        expect(fn () => $extractor->extract("{$this->show}/x.rar", "{$this->show}/out"))
            ->toThrow(ExtractionFailed::class, 'apt install unar');
    });

    it('uses unar for everything when installed', function () {
        $fake = "{$this->base}/unar";
        file_put_contents($fake, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > '{$this->base}/unar.argv'\n");
        chmod($fake, 0755);
        file_put_contents("{$this->show}/x.rar", 'Rar!');

        (new ArchiveExtractor(new LocalShell, 60, unarBinary: $fake))->extract("{$this->show}/x.rar", "{$this->show}/out");

        expect(file("{$this->base}/unar.argv", FILE_IGNORE_NEW_LINES))
            ->toBe(['-q', '-D', '-s', '-o', "{$this->show}/out", '--', "{$this->show}/x.rar"]);
    });

    it('starts the extraction as a detached background job', function () {
        Process::fake();
        makeZip("{$this->show}/pack.zip", ['a.mp4' => 'A']);
        config()->set('media.php_binary', '/usr/bin/php');

        $job = asUser('richard')->postJson('/admin/extract', ['path' => 'TV/Drama/Show', 'name' => 'pack.zip', 'newFolder' => 'Extras'])
            ->assertStatus(202)->assertJsonPath('status', 'queued')->json();

        Process::assertRan(fn (PendingProcess $p) => $p->command[0] === 'sh'
            && str_starts_with($p->command[2], 'setsid nohup ')
            && str_contains($p->command[2], "'media:extract' '{$job['id']}'"));
        asUser('richard')->getJson("/admin/extract/{$job['id']}")->assertJsonPath('status', 'queued');
    });

    it('runs a job to completion and reports it', function () {
        makeZip("{$this->show}/pack.zip", ['a.mp4' => 'A']);
        app()->bind(ArchiveExtractor::class, fn () => new ArchiveExtractor(new LocalShell, 60, unarBinary: 'definitely-not-installed-unar'));
        $id = app(ExtractJobs::class)->create([
            'archive' => "{$this->show}/pack.zip", 'archiveName' => 'pack.zip',
            'destination' => "{$this->show}/Extras", 'destinationPath' => 'TV/Drama/Show/Extras',
        ]);

        expect(Artisan::call('media:extract', ['id' => $id]))->toBe(0);

        asUser('richard')->getJson("/admin/extract/{$id}")
            ->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('destinationPath', 'TV/Drama/Show/Extras');
        expect(file_get_contents("{$this->show}/Extras/a.mp4"))->toBe('A');

        // a finished job can't be re-run
        expect(Artisan::call('media:extract', ['id' => $id]))->toBe(1);
    });

    it('records a failure the page can show', function () {
        file_put_contents("{$this->show}/x.rar", 'Rar!');
        app()->bind(ArchiveExtractor::class, fn () => new ArchiveExtractor(new LocalShell, 60, unarBinary: 'definitely-not-installed-unar'));
        $id = app(ExtractJobs::class)->create(['archive' => "{$this->show}/x.rar", 'destination' => "{$this->show}/out"]);

        Artisan::call('media:extract', ['id' => $id]);

        asUser('richard')->getJson("/admin/extract/{$id}")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', fn (string $e) => str_contains($e, 'apt install unar'));
    });

    it('validates the archive and is admin only', function () {
        asUser('richard')->postJson('/admin/extract', ['path' => 'TV/Drama/Show', 'name' => 'ep1.mp4'])->assertStatus(422);
        asUser('richard')->postJson('/admin/extract', ['path' => 'TV/Drama/Show', 'name' => 'nope.zip'])->assertStatus(422);
        asUser('alice')->postJson('/admin/extract', ['path' => 'TV/Drama/Show', 'name' => 'extras.zip'])->assertForbidden();
        asUser('richard')->getJson('/admin/extract/not-a-job')->assertNotFound();
    });
});

describe('convert', function () {
    beforeEach(function () {
        $this->fake = new class implements Converter
        {
            public array $started = [];

            public bool $configured = true;

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function start(string $source, string $export, array $options, string $tag): string
            {
                $this->started[] = compact('source', 'export', 'options', 'tag');

                return 'job-'.count($this->started);
            }

            public function recent(int $limit = 20): array
            {
                return [['id' => 'job-1', 'tag' => 'reencode-ep1', 'status' => 'processing', 'created' => 1]];
            }
        };
        app()->instance(Converter::class, $this->fake);
        config()->set('media.convert.export_root', '/home/ftp/media/cloudconvert');
    });

    it('starts one job per file with the preset options and mirrored export path', function () {
        mkdir("{$this->base}/share/Video/Uploaded Files/youtube", 0777, true);
        file_put_contents("{$this->base}/share/Video/Uploaded Files/youtube/clip.mp4", 'x');

        asUser('richard')->postJson('/admin/convert', ['path' => 'Uploaded Files/youtube', 'names' => ['clip.mp4'], 'preset' => 'reencode'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('results.0.job', 'job-1');

        expect($this->fake->started[0])->toMatchArray([
            'source' => "{$this->base}/share/Video/Uploaded Files/youtube/clip.mp4",
            'export' => '/home/ftp/media/cloudconvert/youtube/clip.mp4',
            'tag' => 'reencode-clip',
        ])->and($this->fake->started[0]['options']['crf'])->toBe(23);
    });

    it('reports files the preset cannot take', function () {
        asUser('richard')->postJson('/admin/convert', ['path' => 'TV/Drama/Show', 'names' => ['ep1.mp4', 'ep2.mkv', 'nope.mp4'], 'preset' => 'reencode'])
            ->assertOk()->assertJsonPath('failed', 2)
            ->assertJsonPath('results.1.error', 'This preset only converts .mp4 files.');
    });

    it('lists presets and recent jobs, and says when it is not set up', function () {
        asUser('richard')->getJson('/admin/convert/presets')->assertOk()
            ->assertJsonPath('configured', true)->assertJsonPath('presets.0.key', 'reencode');
        asUser('richard')->getJson('/admin/convert/jobs')->assertOk()->assertJsonPath('active', true);

        $this->fake->configured = false;
        asUser('richard')->postJson('/admin/convert', ['path' => 'TV/Drama/Show', 'names' => ['ep1.mp4'], 'preset' => 'reencode'])
            ->assertStatus(503);
    });
});
