<?php

namespace App\Acquire\Iplayer;

use App\Library\DirectoryReader;
use App\Support\Shell\LocalShell;
use Illuminate\Support\Facades\Cache;

/**
 * BBC iPlayer via get-iplayer (port of lib/Iplayer.php).
 *
 * Downloads go through a queue file run by scripts/get_iplayer_download_queue,
 * one batch at a time, as before. Every argument is quoted (S2), PIDs are
 * validated, and the queue is appended to under a lock instead of being
 * overwritten.
 */
class IplayerService
{
    public const PID_PATTERN = '/^[a-z0-9]{8,15}$/';

    /** A download whose files haven't changed for this long is reported as stalled. */
    private const STALLED_AFTER = 1800;

    /** Seconds a converting file must stay the same size to count as finishing. */
    private const FINISHING_AFTER = 15;

    /** Re-try a failed info lookup after this long. */
    private const INFO_RETRY_AFTER = 900;

    /** At most this many info lookups are started per status check. */
    private const INFO_FETCHES_PER_CALL = 3;

    /**
     * @param  list<string>  $command  e.g. ['sudo', 'get-iplayer']
     * @param  array{search: string, download: string, info: string}  $logs
     * @param  list<string>  $sdCopyArgs
     */
    public function __construct(
        private readonly LocalShell $shell,
        private readonly DirectoryReader $reader,
        private readonly array $command,
        private readonly string $downloadDir,
        private readonly string $publicDir,
        private readonly string $queueScript,
        private readonly array $logs,
        private readonly array $sdCopyArgs,
        private readonly int $searchTimeout = 300,
    ) {}

    /**
     * Search the programme index. Returns [{index, label, pid}].
     *
     * @return list<array{index: string, label: string, pid: string}>
     */
    public function search(string $keywords): array
    {
        $result = $this->shell->run([...$this->command, '--', $keywords], $this->searchTimeout);
        $output = $result->output();
        @file_put_contents($this->logs['search'], $output); // listed on the Logs page, as before

        if (! $result->successful() && trim($output) === '') {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'get-iplayer failed.');
        }

        return self::parseSearch($output);
    }

    /**
     * The lines between "Matches:" and the trailing "INFO:" lines, each
     * "<index>:\t<name - episode>, <channel>, <pid>".
     *
     * @return list<array{index: string, label: string, pid: string}>
     */
    public static function parseSearch(string $output): array
    {
        $results = [];
        $started = false;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! $started) {
                $started = str_contains($line, 'Matches:');

                continue;
            }
            if (str_contains($line, 'INFO:')) {
                break;
            }
            if (! preg_match('/^\s*(\d+):\s*(.+)$/', $line, $m)) {
                continue;
            }
            $label = trim($m[2]);
            $parts = explode(', ', $label);
            $pid = strtolower(trim((string) end($parts)));
            if (! preg_match(self::PID_PATTERN, $pid)) {
                continue;
            }
            $results[] = ['index' => $m[1], 'label' => $label, 'pid' => $pid];
        }

        return $results;
    }

    /**
     * A PID, or an iPlayer/programmes URL containing one. Null if neither.
     */
    public static function normalisePid(string $input): ?string
    {
        $input = trim($input);
        if (preg_match(self::PID_PATTERN, strtolower($input))) {
            return strtolower($input);
        }
        if (preg_match('~bbc\.co\.uk/(?:iplayer/(?:episode|episodes)|programmes)/([a-z0-9]{8,15})(?:[/?#]|$)~i', $input, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Queue a download and make sure the queue runner is going.
     *
     * @param  list<string>  $pids  validated PIDs
     * @return list<string> the queued command lines
     */
    public function queue(array $pids, bool $force, bool $sdCopy, bool $recursive): array
    {
        $pidArg = implode(',', $pids);
        $lines = [LocalShell::join([
            ...$this->command, '--pid', $pidArg,
            ...($force ? ['--force', '--ffmpeg-force'] : []),
            ...($recursive ? ['--pid-recursive'] : []),
        ])];
        if ($sdCopy) {
            $lines[] = LocalShell::join([
                ...$this->command, '--pid', $pidArg,
                ...($recursive ? ['--pid-recursive'] : []),
                ...$this->sdCopyArgs,
            ]);
        }

        $this->appendToQueue($lines);
        $this->shell->background(['sh', $this->queueScript, $this->queueFile()], $this->logs['download']);

        return $lines;
    }

    /**
     * Everything in the download folder, with progress, plus the queue.
     *
     * @return array{active: bool, queue: array{pending: list<string>, running: list<string>}, items: list<array<string, mixed>>}
     */
    public function status(int $now): array
    {
        $entries = is_dir($this->downloadDir) ? $this->reader->read($this->downloadDir) : [];
        $resolved = IplayerStatusResolver::resolve($entries);
        $queue = $this->queueContents();
        $queueBusy = $queue['pending'] !== [] || $queue['running'] !== [];

        $fetches = 0;
        $items = [];
        $active = $queueBusy;
        foreach ($resolved as $item) {
            $info = $this->cachedInfo($item['pid']);
            if ($info === null && $fetches < self::INFO_FETCHES_PER_CALL && $this->fetchInfo($item['pid'], $now)) {
                $fetches++;
            }
            $title = $info?->title() ?? $item['nameTitle'];

            $tracks = [];
            foreach (['main' => $item['main'], 'sd' => $item['sd']] as $kind => $track) {
                if ($track === null) {
                    continue;
                }
                // A file that's being downloaded or converted changes constantly,
                // so one untouched for a long while has been abandoned.
                $stalled = $track['status'] !== IplayerStatusResolver::COMPLETED
                    && $now - $track['mtime'] > self::STALLED_AFTER;
                $status = $stalled ? 'stalled' : $this->finishing($track, $now);
                $active = $active || ($status !== IplayerStatusResolver::COMPLETED && ! $stalled);
                $tracks[$kind] = [
                    'status' => $status,
                    'label' => $stalled ? 'Stopped (no progress for a while)' : IplayerStatusResolver::LABELS[$status],
                    'file' => $track['file'],
                    'size' => $track['size'],
                    'expectedSize' => match ($status) {
                        // BBC's estimate for the quality being fetched
                        IplayerStatusResolver::DOWNLOADING => $info?->expectedSize($kind === 'sd'),
                        // the file being converted or copied from (the MP4 ends up ~3% smaller)
                        IplayerStatusResolver::CONVERTING, IplayerStatusResolver::TAGGING => $track['basis'],
                        default => null,
                    },
                    'updated' => $track['mtime'],
                ];
            }

            $items[] = ['pid' => $item['pid'], 'title' => $title] + ['main' => $tracks['main'] ?? null, 'sd' => $tracks['sd'] ?? null];
        }

        return ['active' => $active, 'queue' => $queue, 'items' => $items];
    }

    /**
     * After converting, ffmpeg rewrites the whole .partial.mp4 in place to
     * put its index at the front: the file keeps changing but stops growing,
     * for a few minutes on media02. Spot that by remembering each file's size
     * between status checks.
     *
     * @param  array{status: string, file: string, size: ?float, mtime: int}  $track
     */
    private function finishing(array $track, int $now): string
    {
        if ($track['status'] !== IplayerStatusResolver::CONVERTING || ! str_ends_with($track['file'], '.partial.mp4') || ! $track['size']) {
            return $track['status'];
        }
        $key = 'iplayer:growth:'.md5($track['file']);
        $seen = Cache::get($key);
        if (! is_array($seen) || abs($seen['size'] - $track['size']) >= 0.5) {
            Cache::put($key, ['size' => $track['size'], 'since' => $now], 3600);

            return $track['status'];
        }

        return $now - $seen['since'] >= self::FINISHING_AFTER && $now - $track['mtime'] < 60
            ? IplayerStatusResolver::FINISHING
            : $track['status'];
    }

    /**
     * @return array{pending: list<string>, running: list<string>}
     */
    public function queueContents(): array
    {
        $read = fn (string $file) => is_file($file)
            ? array_values(array_filter(array_map('trim', file($file) ?: []), 'strlen'))
            : [];

        return ['pending' => $read($this->queueFile()), 'running' => $read($this->queueFile().'_active')];
    }

    public function queueFile(): string
    {
        return $this->publicDir.'/download_queue';
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendToQueue(array $lines): void
    {
        if (! is_dir($this->publicDir)) {
            @mkdir($this->publicDir, 0775, true);
        }
        // The runner takes the same lock while it swaps the queue out, so a
        // line is never added to a file that's being renamed away.
        $lock = fopen($this->queueFile().'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('The download queue couldn\'t be opened.');
        }
        try {
            flock($lock, LOCK_EX);
            $ok = file_put_contents($this->queueFile(), implode("\n", $lines)."\n", FILE_APPEND);
            if ($ok === false) {
                throw new \RuntimeException('The download queue couldn\'t be written.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function infoFile(string $pid): string
    {
        return "{$this->publicDir}/{$pid}/fileinfo";
    }

    private function cachedInfo(string $pid): ?IplayerInfo
    {
        $file = $this->infoFile($pid);
        if (! is_file($file) || filesize($file) === 0) {
            return null;
        }

        return IplayerInfo::parse((string) file_get_contents($file));
    }

    /**
     * Look up a programme's details in the background (title, expected size),
     * once; a failed lookup is retried after a while.
     */
    private function fetchInfo(string $pid, int $now): bool
    {
        $file = $this->infoFile($pid);
        $marker = $file.'.fetching';
        if (is_file($marker) && $now - (int) filemtime($marker) < self::INFO_RETRY_AFTER) {
            return false;
        }
        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0775, true)) {
            return false;
        }
        @touch($marker);

        // Write to a temp file and only keep a non-empty result.
        $script = '"$@" > "$0.tmp" 2>/dev/null; if [ -s "$0.tmp" ] && grep -q "^title:" "$0.tmp"; then mv "$0.tmp" "$0"; rm -f "$0.fetching"; else rm -f "$0.tmp"; fi';
        $this->shell->background(['sh', '-c', $script, $file, ...$this->command, '--info', '--pid', $pid], $this->logs['info']);

        return true;
    }
}
