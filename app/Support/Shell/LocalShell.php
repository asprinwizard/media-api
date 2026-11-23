<?php

namespace App\Support\Shell;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs commands on this server.
 *
 * Commands are always argument arrays, so no shell parses user input.
 * The only place a shell is involved is background(), where every part is
 * escaped with escapeshellarg().
 */
class LocalShell
{
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv, int $timeout = self::DEFAULT_TIMEOUT): ProcessResult
    {
        return Process::timeout($timeout)->run($argv);
    }

    /**
     * Start a long-running job and return immediately. setsid + closed stdin
     * detach it from the Apache child so it survives a graceful restart.
     *
     * The log is replaced, or added to when $append is set (for short jobs
     * that run side by side and would otherwise wipe each other's output).
     *
     * @param  list<string>  $argv
     */
    public function background(array $argv, string $logFile, bool $append = false): void
    {
        Process::run(['sh', '-c', self::backgroundCommand($argv, $logFile, $append)]);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function backgroundCommand(array $argv, string $logFile, bool $append = false): string
    {
        return 'setsid nohup '.self::join($argv)
            .($append ? ' >> ' : ' > ').escapeshellarg($logFile).' 2>&1 < /dev/null &';
    }

    /**
     * Quote each argument for one round of POSIX shell parsing.
     *
     * @param  list<string>  $argv
     */
    public static function join(array $argv): string
    {
        return implode(' ', array_map('escapeshellarg', $argv));
    }
}
