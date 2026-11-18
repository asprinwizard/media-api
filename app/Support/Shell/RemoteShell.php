<?php

namespace App\Support\Shell;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs commands on another server over ssh (media01, media08).
 *
 * ssh joins its arguments and the REMOTE shell parses the result once, so
 * the remote argv is escaped exactly once here. Locally no shell is involved
 * (LocalShell::run takes an argv array).
 */
class RemoteShell
{
    public const DEFAULT_TIMEOUT = 120; // sleeping drives can take a while to spin up

    public function __construct(
        private readonly LocalShell $local,
        private readonly string $sshBinary,
        private readonly string $target, // e.g. "root@media01"
    ) {}

    /**
     * @param  list<string>  $remoteArgv
     */
    public function run(array $remoteArgv, int $timeout = self::DEFAULT_TIMEOUT): ProcessResult
    {
        return $this->local->run($this->command($remoteArgv), $timeout);
    }

    /**
     * Run a fixed shell script remotely with positional arguments ($1, $2 ...).
     * The script itself must be a constant, never built from user input.
     *
     * @param  list<string>  $args
     */
    public function script(string $script, array $args = [], int $timeout = self::DEFAULT_TIMEOUT): ProcessResult
    {
        return $this->run(['sh', '-c', $script, 'sh', ...$args], $timeout);
    }

    /**
     * The local argv that will be executed (exposed for tests).
     *
     * @param  list<string>  $remoteArgv
     * @return list<string>
     */
    public function command(array $remoteArgv): array
    {
        return [$this->sshBinary, $this->target, LocalShell::join($remoteArgv)];
    }
}
