<?php

namespace App\Ops;

use App\Support\Shell\LocalShell;
use App\Support\Shell\RemoteShell;

/**
 * Tools › Drives (port of tools/hard-drives.php): disk space, whether each
 * drive is spinning, and where the main library folders point, for media02
 * (locally) and media01 (one ssh call). The same fixed script runs on both.
 */
class DriveReport
{
    /**
     * $1 library root, $2 "sudo" or "", $3 hdparm path, then folders to check.
     * hdparm -C only asks the drive's power state; it doesn't wake it.
     */
    public const SCRIPT = <<<'SH'
        root=$1; sudo=$2; hd=$3; shift 3
        echo '##DF'
        df -P -B1 2>/dev/null
        echo '##DRIVES'
        for d in /dev/sd[a-z]; do
          [ -b "$d" ] || continue
          if [ -n "$sudo" ]; then out=$(sudo -n "$hd" -C "$d" 2>/dev/null); else out=$("$hd" -C "$d" 2>/dev/null); fi
          state=$(printf '%s\n' "$out" | sed -n 's/.*drive state is:[[:space:]]*//p' | head -n 1)
          printf '%s\t%s\n' "$d" "$state"
        done
        echo '##LINKS'
        for p in "$@"; do
          if [ -L "$root/$p" ]; then k=link; elif [ -d "$root/$p" ]; then k=dir; else k=missing; fi
          printf '%s\t%s\t%s\n' "$p" "$k" "$(readlink -f -- "$root/$p" 2>/dev/null)"
        done
        SH;

    /** Pseudo and system filesystems left out of the space table. */
    private const SKIP_FS = '/^(tmpfs|devtmpfs|udev|none|overlay|shm|sysfs|proc|cgroup.*)$/';

    private const SKIP_MOUNT = '~^/(snap|run|dev|sys|proc)(/|$)~';

    /**
     * @param  list<string>  $linkFolders
     */
    public function __construct(
        private readonly LocalShell $local,
        private readonly RemoteShell $media01,
        private readonly array $linkFolders,
        private readonly string $media02Root,
        private readonly string $hdparm,
        private readonly bool $hdparmSudo,
        private readonly string $media01Root,
        private readonly string $media01Hdparm,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function media02(): array
    {
        $result = $this->local->run(['sh', '-c', self::SCRIPT, 'sh', $this->media02Root, $this->hdparmSudo ? 'sudo' : '', $this->hdparm, ...$this->linkFolders], 120);

        return ['server' => 'media02', 'error' => null] + self::parse($result->output());
    }

    /**
     * @return array<string, mixed>
     */
    public function media01(): array
    {
        $result = $this->media01->script(self::SCRIPT, [$this->media01Root, '', $this->media01Hdparm, ...$this->linkFolders], 120);
        if ($result->exitCode() === 255) {
            return ['server' => 'media01', 'error' => 'media01 can\'t be reached right now.', 'space' => [], 'drives' => [], 'links' => []];
        }

        return ['server' => 'media01', 'error' => null] + self::parse($result->output());
    }

    /**
     * @return array{space: list<array<string, mixed>>, drives: list<array{device: string, state: string}>, links: list<array{folder: string, kind: string, target: ?string}>}
     */
    public static function parse(string $output): array
    {
        $sections = ['DF' => [], 'DRIVES' => [], 'LINKS' => []];
        $current = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^##(DF|DRIVES|LINKS)$/', $line, $m)) {
                $current = $m[1];

                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $sections[$current][] = $line;
            }
        }

        $space = [];
        foreach (array_slice($sections['DF'], 1) as $line) { // first line is the header
            $parts = preg_split('/\s+/', trim($line), 6);
            if (count($parts) < 6 || ! is_numeric($parts[1])) {
                continue;
            }
            [$fs, $size, $used, $available, $percent, $mount] = $parts;
            if (preg_match(self::SKIP_FS, $fs) || str_starts_with($fs, '/dev/loop') || preg_match(self::SKIP_MOUNT, $mount)) {
                continue;
            }
            $space[] = [
                'filesystem' => $fs,
                'mount' => $mount,
                'size' => (float) $size,
                'used' => (float) $used,
                'available' => (float) $available,
                'percent' => (int) rtrim($percent, '%'),
            ];
        }
        usort($space, fn ($a, $b) => strnatcmp($a['mount'], $b['mount']));

        $drives = [];
        foreach ($sections['DRIVES'] as $line) {
            [$device, $state] = array_pad(explode("\t", $line, 2), 2, '');
            $state = trim($state);
            $drives[] = ['device' => $device, 'state' => $state === '' ? 'unknown' : $state];
        }

        $links = [];
        foreach ($sections['LINKS'] as $line) {
            [$folder, $kind, $target] = array_pad(explode("\t", $line, 3), 3, '');
            $links[] = ['folder' => $folder, 'kind' => $kind, 'target' => $target === '' ? null : $target];
        }

        return ['space' => $space, 'drives' => $drives, 'links' => $links];
    }
}
