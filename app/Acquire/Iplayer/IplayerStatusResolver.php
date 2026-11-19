<?php

namespace App\Acquire\Iplayer;

use App\Library\DirectoryEntry;

/**
 * Works out what each download in the get_iplayer folder is doing from the
 * file names alone (port of Iplayer::getStatus, plan §5.4). Pure: no I/O.
 *
 * get_iplayer names files "<prefix>---<pid><suffix>". The suffix tells the
 * stage; when several files exist for one pid, the most advanced unfinished
 * stage wins, in this order:
 *
 *   temp (-temp…) → encoding (.partial.mp4) → converting (.dash.ts)
 *   → audio (.audio.m4a/.audio.ts) → video (.video.m4v/.hls.ts/.video.ts)
 *   → completed (.mp4)
 *
 * "SD copies" (prefix ending "_(SD)") are tracked separately, as the old
 * code intended (its SD matching never worked, §3.5).
 */
final class IplayerStatusResolver
{
    public const TEMP = 'temp';

    public const ENCODING = 'encoding';

    public const AUDIO = 'audio';

    public const VIDEO = 'video';

    public const COMPLETED = 'completed';

    public const LABELS = [
        self::TEMP => 'Writing to temp file',
        self::ENCODING => 'Encoding',
        self::AUDIO => 'Downloading audio',
        self::VIDEO => 'Downloading video',
        self::COMPLETED => 'Completed',
    ];

    /** [status, test on the part after the pid], in priority order. */
    private const STAGES = [
        [self::TEMP, '/^-temp/'],
        [self::ENCODING, '/^\.partial\.mp4/'],
        [self::ENCODING, '/^\.dash\.ts$/'],
        [self::AUDIO, '/^\.audio\.(m4a|ts)$/'],
        [self::VIDEO, '/^\.(video\.m4v|hls\.ts|video\.ts)$/'],
        [self::COMPLETED, '/^\.mp4$/'],
    ];

    private const PID = '/^(?<prefix>.*?)---(?<pid>[a-z0-9]{8,})(?<suffix>[.\-].*)$/i';

    /**
     * @param  list<DirectoryEntry>  $entries  the download folder's files
     * @return list<array{pid: string, main: ?array{status: string, file: string, size: ?float, mtime: int}, sd: ?array{status: string, file: string, size: ?float, mtime: int}, nameTitle: string}>
     *                                                                                                                                                                                               newest activity first
     */
    public static function resolve(array $entries): array
    {
        /** @var array<string, array{main: list<array{DirectoryEntry, string}>, sd: list<array{DirectoryEntry, string}>, prefix: string}> $byPid */
        $byPid = [];
        foreach ($entries as $entry) {
            if ($entry->size === null || ! preg_match(self::PID, $entry->name, $m)) {
                continue; // folders, broken links, files that aren't get_iplayer's
            }
            $pid = strtolower($m['pid']);
            $isSd = str_ends_with($m['prefix'], '_(SD)') || str_ends_with($m['prefix'], ' (SD)');
            $byPid[$pid] ??= ['main' => [], 'sd' => [], 'prefix' => ''];
            $byPid[$pid][$isSd ? 'sd' : 'main'][] = [$entry, $m['suffix']];
            if (! $isSd || $byPid[$pid]['prefix'] === '') {
                $byPid[$pid]['prefix'] = $isSd ? preg_replace('/[_ ]\(SD\)$/', '', $m['prefix']) : $m['prefix'];
            }
        }

        $items = [];
        foreach ($byPid as $pid => $group) {
            $main = self::track($group['main']);
            $sd = self::track($group['sd']);
            if ($main === null && $sd === null) {
                continue;
            }
            $items[] = [
                'pid' => (string) $pid,
                'main' => $main,
                'sd' => $sd,
                'nameTitle' => self::titleFromPrefix($group['prefix']),
            ];
        }

        // Most recent activity first.
        usort($items, fn ($a, $b) => max($b['main']['mtime'] ?? 0, $b['sd']['mtime'] ?? 0) <=> max($a['main']['mtime'] ?? 0, $a['sd']['mtime'] ?? 0));

        return $items;
    }

    /**
     * @param  list<array{DirectoryEntry, string}>  $files
     * @return ?array{status: string, file: string, size: ?float, mtime: int}
     */
    private static function track(array $files): ?array
    {
        foreach (self::STAGES as [$status, $pattern]) {
            foreach ($files as [$entry, $suffix]) {
                if (preg_match($pattern, $suffix)) {
                    return ['status' => $status, 'file' => $entry->name, 'size' => $entry->size, 'mtime' => $entry->mtime];
                }
            }
        }

        return null;
    }

    /**
     * "This_City_Is_Ours____s02e08_-_Episode_8" → "This City Is Ours s02e08 - Episode 8"
     * (port of getTitleFromFilename).
     */
    public static function titleFromPrefix(string $prefix): string
    {
        return trim((string) preg_replace('/_+/', ' ', $prefix));
    }
}
