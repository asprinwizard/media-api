<?php

namespace App\Acquire\Youtube;

use App\Support\Shell\LocalShell;

/**
 * YouTube downloads via yt-dlp (port of lib/Youtube.php).
 *
 * Formats come from `yt-dlp -J` (JSON) rather than parsing the
 * --list-formats table. Downloads run in the background, one at a time,
 * into youtube/<channel>/<title>.<ext>, and finish by writing a marker line
 * to the log so the status check knows when they've ended.
 */
class YoutubeService
{
    public const FINISHED_MARKER = '[media-site] finished, exit code ';

    /** A download whose log hasn't changed for this long is treated as dead. */
    private const STALLED_AFTER = 1800;

    public function __construct(
        private readonly LocalShell $shell,
        private readonly string $binary,
        private readonly string $cookies,
        private readonly string $downloadDir,
        private readonly string $log,
        private readonly int $infoTimeout = 180,
    ) {}

    /**
     * What kind of link this is (port of xhr_get_info's checks): a single
     * video (normalised to a plain watch URL), a playlist, or not YouTube.
     *
     * @return array{kind: 'video'|'playlist'|'invalid', url: ?string}
     */
    public static function classify(string $input): array
    {
        $parts = parse_url(trim($input));
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)
            || ! preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host)) {
            return ['kind' => 'invalid', 'url' => null];
        }
        parse_str($parts['query'] ?? '', $query);
        $path = $parts['path'] ?? '';

        $id = null;
        if ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
            $id = trim($path, '/');
        } elseif (is_string($query['v'] ?? null)) {
            $id = $query['v'];
        } elseif (preg_match('~^/(shorts|live|embed)/([^/]+)~', $path, $m)) {
            $id = $m[2];
        }
        if ($id !== null && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            return ['kind' => 'video', 'url' => "https://www.youtube.com/watch?v={$id}"];
        }

        $list = $query['list'] ?? null;
        if (is_string($list) && preg_match('/^[A-Za-z0-9_-]{10,64}$/', $list)) {
            return ['kind' => 'playlist', 'url' => "https://www.youtube.com/playlist?list={$list}"];
        }

        return ['kind' => 'invalid', 'url' => null];
    }

    /**
     * Title and formats for a single video.
     *
     * @return array{title: ?string, channel: ?string, duration: ?int, thumbnail: ?string, formats: array{video: list<array<string, mixed>>, audio: list<array<string, mixed>>}}
     */
    public function info(string $url): array
    {
        $result = $this->shell->run([
            $this->binary, '-J', '--no-playlist', '--no-warnings', ...$this->cookieArgs(), '--', $url,
        ], $this->infoTimeout);

        $data = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($data)) {
            $error = trim($result->errorOutput());
            $error = preg_replace('/^ERROR:\s*/m', '', $error) ?: 'yt-dlp couldn\'t read that video.';
            throw new \RuntimeException(mb_substr($error, 0, 300));
        }

        return [
            'title' => $data['title'] ?? null,
            'channel' => $data['channel'] ?? $data['uploader'] ?? null,
            'duration' => isset($data['duration']) ? (int) $data['duration'] : null,
            'thumbnail' => $data['thumbnail'] ?? null,
            'formats' => self::formats($data['formats'] ?? []),
        ];
    }

    /**
     * Split yt-dlp's formats into video (anything with a picture) and
     * audio-only, best first, skipping storyboards and the like.
     *
     * @param  list<array<string, mixed>>  $formats
     * @return array{video: list<array<string, mixed>>, audio: list<array<string, mixed>>}
     */
    public static function formats(array $formats): array
    {
        $out = ['video' => [], 'audio' => []];
        foreach ($formats as $f) {
            $vcodec = (string) ($f['vcodec'] ?? 'none');
            $acodec = (string) ($f['acodec'] ?? 'none');
            $ext = (string) ($f['ext'] ?? '');
            if ($ext === 'mhtml' || ($vcodec === 'none' && $acodec === 'none') || ! isset($f['format_id'])) {
                continue;
            }
            $type = $vcodec === 'none' ? 'audio' : 'video';
            $out[$type][] = [
                'id' => (string) $f['format_id'],
                'extension' => $ext,
                'resolution' => $type === 'audio' ? 'audio only' : (string) ($f['resolution'] ?? (isset($f['height']) ? "{$f['height']}p" : '')),
                'height' => isset($f['height']) ? (int) $f['height'] : null,
                'fps' => isset($f['fps']) ? (float) $f['fps'] : null,
                'filesize' => isset($f['filesize']) ? (float) $f['filesize'] : (isset($f['filesize_approx']) ? (float) $f['filesize_approx'] : null),
                'approximate' => ! isset($f['filesize']) && isset($f['filesize_approx']),
                'bitrate' => isset($f['tbr']) ? (float) $f['tbr'] : null,
                'vcodec' => $vcodec === 'none' ? null : $vcodec,
                'acodec' => $acodec === 'none' ? null : $acodec,
                'note' => (string) ($f['format_note'] ?? ''),
                'hasAudio' => $acodec !== 'none',
            ];
        }
        usort($out['video'], fn ($a, $b) => [$b['height'] ?? 0, $b['fps'] ?? 0, $b['bitrate'] ?? 0] <=> [$a['height'] ?? 0, $a['fps'] ?? 0, $a['bitrate'] ?? 0]);
        usort($out['audio'], fn ($a, $b) => ($b['bitrate'] ?? 0) <=> ($a['bitrate'] ?? 0));

        return $out;
    }

    /**
     * Start a download in the background. With no formats chosen yt-dlp picks
     * the best (and for playlists carries on past broken entries, as before).
     */
    public function download(string $url, ?string $video, ?string $audio, bool $playlist): void
    {
        $format = implode('+', array_filter([$video, $audio], fn ($f) => $f !== null && $f !== ''));
        $argv = [
            $this->binary, '--newline',
            ...($format !== '' ? ['-f', $format] : []),
            ...($playlist ? ['--ignore-errors'] : ['--no-playlist']),
            '-o', $this->downloadDir.'/%(channel)s/%(title)s.%(ext)s',
            ...$this->cookieArgs(),
            '--', $url,
        ];

        if (! is_dir(dirname($this->log))) {
            @mkdir(dirname($this->log), 0775, true);
        }
        // Run yt-dlp, then record that it ended (and how).
        $this->shell->background(
            ['sh', '-c', '"$@"; echo "'.self::FINISHED_MARKER.'$?"', 'yt-dlp', ...$argv],
            $this->log,
        );
    }

    /**
     * The current/last download, from its log.
     *
     * @return array{active: bool, state: 'idle'|'downloading'|'finished'|'failed', line: ?string, percent: ?float, item: ?string, destination: ?string, error: ?string}
     */
    public function status(int $now): array
    {
        if (! is_file($this->log)) {
            return ['active' => false, 'state' => 'idle', 'line' => null, 'percent' => null, 'item' => null, 'destination' => null, 'error' => null];
        }

        return self::parseLog((string) file_get_contents($this->log), $now - (int) filemtime($this->log) > self::STALLED_AFTER);
    }

    /**
     * @return array{active: bool, state: 'idle'|'downloading'|'finished'|'failed', line: ?string, percent: ?float, item: ?string, destination: ?string, error: ?string}
     */
    public static function parseLog(string $log, bool $stale = false): array
    {
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $log) ?: []), 'strlen'));
        $exit = null;
        $percent = null;
        $item = null;
        $destination = null;
        $error = null;
        $last = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, self::FINISHED_MARKER)) {
                $exit = (int) substr($line, strlen(self::FINISHED_MARKER));

                continue;
            }
            $last = $line;
            if (preg_match('/^\[download\]\s+([\d.]+)%/', $line, $m)) {
                $percent = (float) $m[1];
            } elseif (preg_match('/^\[download\] Downloading (?:item|video) (\d+ of \d+)/', $line, $m)) {
                $item = $m[1];
                $percent = null;
            } elseif (preg_match('/^\[(?:download|Merger|ExtractAudio)\] (?:Destination: |Merging formats into ")(.+?)"?$/', $line, $m)) {
                $destination = basename($m[1]);
            } elseif (str_starts_with($line, 'ERROR:')) {
                $error = trim(substr($line, 6));
            }
        }

        $state = match (true) {
            $exit === null && ! $stale => 'downloading',
            $exit === 0 => 'finished',
            $exit === null && $error === null && $percent !== null && $percent >= 100 => 'finished',
            default => 'failed',
        };

        return [
            'active' => $state === 'downloading',
            'state' => $state,
            'line' => $last,
            'percent' => $percent,
            'item' => $item,
            'destination' => $destination,
            'error' => $state === 'failed' ? ($error ?? ($stale ? 'The download stopped without finishing.' : 'yt-dlp exited with an error.')) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function cookieArgs(): array
    {
        return is_file($this->cookies) ? ['--cookies', $this->cookies] : [];
    }
}
