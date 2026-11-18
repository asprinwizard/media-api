<?php

use App\Support\Shell\LocalShell;
use App\Support\Shell\RemoteShell;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

const HOSTILE = "Show's \"Name\" \$(rm -rf /) `id`; | > x & ünï";

it('runs local commands as argv arrays (no shell)', function () {
    Process::fake();

    (new LocalShell)->run(['ls', '-F', HOSTILE], 5);

    Process::assertRan(fn (PendingProcess $p) => $p->command === ['ls', '-F', HOSTILE] && $p->timeout === 5);
});

it('quotes every argument of a background command', function () {
    $cmd = LocalShell::backgroundCommand(['yt-dlp', HOSTILE], '/var/log/yt dlp.log');

    expect($cmd)->toStartWith('setsid nohup ')
        ->toEndWith("> '/var/log/yt dlp.log' 2>&1 < /dev/null &")
        ->toContain(escapeshellarg(HOSTILE));
});

it('background command round-trips hostile arguments through a real shell', function () {
    // Replace the program with printf so the shell shows us the argv it parsed.
    $dir = tempDir('shell');
    $log = "$dir/out.log";

    $cmd = str_replace('setsid nohup ', '', LocalShell::backgroundCommand(['printf', '%s\n', HOSTILE, 'second'], $log));
    exec('sh -c '.escapeshellarg(rtrim($cmd, ' &')));

    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe([HOSTILE, 'second']);
});

it('escapes the remote argv exactly once for the remote shell', function () {
    $shell = new RemoteShell(new LocalShell, 'ssh22', 'root@media01');

    $command = $shell->command(['mkdir', '-p', '/sharedfolders/Video/'.HOSTILE]);

    expect($command[0])->toBe('ssh22')
        ->and($command[1])->toBe('root@media01')
        ->and($command[2])->toBe("'mkdir' '-p' ".escapeshellarg('/sharedfolders/Video/'.HOSTILE));

    // What the remote shell would see: parse $command[2] once.
    $dir = tempDir('remote');
    exec('sh -c '.escapeshellarg('printf "%s\n" '.substr($command[2], strlen("'mkdir' "))).' > '.escapeshellarg("$dir/argv"));
    expect(file("$dir/argv", FILE_IGNORE_NEW_LINES))->toBe(['-p', '/sharedfolders/Video/'.HOSTILE]);
});

it('runs fixed remote scripts with quoted positional arguments', function () {
    Process::fake();
    $shell = new RemoteShell(new LocalShell, 'ssh22', 'root@media01');

    $shell->script('test -d "$1" && echo dir', ['/sharedfolders/Video/'.HOSTILE]);

    Process::assertRan(fn (PendingProcess $p) => $p->command === [
        'ssh22', 'root@media01',
        LocalShell::join(['sh', '-c', 'test -d "$1" && echo dir', 'sh', '/sharedfolders/Video/'.HOSTILE]),
    ]);
});
