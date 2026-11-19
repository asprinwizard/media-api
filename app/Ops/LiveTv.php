<?php

namespace App\Ops;

use App\Support\Shell\LocalShell;
use App\Support\Shell\RemoteShell;

/**
 * Live TV box (media08): reachability, Wake-on-LAN and power actions
 * (port of lib/LiveStream.php).
 *
 * The old status check could hold a PHP worker for two minutes while the
 * box booted (S8). Here every call returns at once and the page polls.
 */
class LiveTv
{
    /** Power actions over ssh: fixed commands only. */
    public const ACTIONS = [
        'shutdown' => ['sudo', 'shutdown', '-h', 'now'],
        'reboot' => ['sudo', 'reboot'],
        'sleep' => ['sudo', 'systemctl', 'suspend'],
    ];

    public function __construct(
        private readonly LocalShell $local,
        private readonly RemoteShell $remote,
        private readonly string $host,
        private readonly int $checkPort,
        private readonly ?string $mac,
        private readonly string $wakeonlan,
    ) {}

    /** Is the box up? A quick TCP connect to its ssh port (as before). */
    public function online(float $timeout = 2.0): bool
    {
        $socket = @fsockopen($this->host, $this->checkPort, $errno, $error, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    public function canWake(): bool
    {
        return self::validMac($this->mac);
    }

    public function wake(): void
    {
        if (! $this->canWake()) {
            throw new \RuntimeException('Wake-on-LAN isn\'t set up: STREAMER_MAC_ADDRESS is missing from media-api\'s .env.');
        }
        $result = $this->local->run([$this->wakeonlan, (string) $this->mac], 15);
        if (! $result->successful()) {
            throw new \RuntimeException('wakeonlan failed: '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 200));
        }
    }

    /**
     * Shut down, reboot or sleep. The box drops the ssh connection as it goes,
     * so only "couldn't connect at all" counts as a failure.
     */
    public function power(string $action): void
    {
        $command = self::ACTIONS[$action] ?? throw new \InvalidArgumentException("Unknown action [{$action}].");
        $result = $this->remote->run($command, 20);
        if ($result->exitCode() === 255 && ! $this->wentDown($result->errorOutput())) {
            throw new \RuntimeException('Couldn\'t reach the Live TV box over ssh.');
        }
        if ($result->exitCode() !== 0 && $result->exitCode() !== 255) {
            throw new \RuntimeException('The Live TV box refused: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }
    }

    public static function validMac(?string $mac): bool
    {
        return $mac !== null && (bool) preg_match('/^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/i', $mac);
    }

    private function wentDown(string $stderr): bool
    {
        return (bool) preg_match('/closed by remote host|Connection reset|broken pipe/i', $stderr);
    }
}
