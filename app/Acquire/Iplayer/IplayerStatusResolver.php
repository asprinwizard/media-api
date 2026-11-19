<?php

namespace App\Acquire\Iplayer;

use App\Library\DirectoryEntry;

/**
 * Works out what each download in the get_iplayer folder is doing from the
 * file names alone (plan §5.4). Pure: no I/O.
 *
 * get_iplayer (v3.36, checked against a real download on media02, Nov 2026)
 * names files "<prefix>---<pid><suffix>" and goes through:
 *
 *   1. downloading  .video.ts (+ .video.txt), sound and picture together
 *   2. converting   .video.ts renamed to .hls.ts, ffmpeg writes .partial.mp4
 *                   (ffmpeg then rewrites .partial.mp4 in place for a few
 *                   minutes without it growing; IplayerService spots that)
 *   3. tagging      .partial.mp4 renamed to .mp4, cover art saved as .jpg,
 *                   then a tagged copy written to -temp-<n>.mp4
 *   4. completed    .mp4 alone
 *
 * Older modes used separate .audio.* / .video.m4v / .dash.ts files; those
 * are still recognised. "SD copies" (prefix ending "(SD)") are tracked
 * separately.
 */
final class IplayerStatusResolver
{
    public const DOWNLOADING = 'downloading';

    public const AUDIO = 'audio';

    public const CONVERTING = 'converting';

    /** Set by IplayerService, not here: converting, but the file has stopped growing. */
    public const FINISHING = 'finishing';

    public const TAGGING = 'tagging';

    public const COMPLETED = 'completed';

    public const LABELS = [
        self::DOWNLOADING => 'Downloading',
        self::AUDIO => 'Downloading audio',
        self::CONVERTING => 'Converting to MP4',
        self::FINISHING => 'Finishing the MP4',
        self::TAGGING => 'Adding tags and artwork',
        self::COMPLETED => 'Completed',
    ];

    private const PID = '/^(?<prefix>.*?)---(?<pid>[a-z0-9]{8,})(?<suffix>[.\-].*)$/i';

    /**
     * @param  list<DirectoryEntry>  $entries  the download folder's files
     * @return list<array{pid: string, main: ?array{status: string, file: string, size: ?float, basis: ?float, mtime: int}, sd: ?array{status: string, file: string, size: ?float, basis: ?float, mtime: int}, nameTitle: string}>
     *                                                                                                                                                                                                                             newest activity first
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
     * The stage one track (main or SD) is at, the file that shows it, and the
     * size of the file it is being made from ($basis, for a progress bar)
     * when that's known from the folder alone.
     *
     * @param  list<array{DirectoryEntry, string}>  $files
     * @return ?array{status: string, file: string, size: ?float, basis: ?float, mtime: int}
     */
    private static function track(array $files): ?array
    {
        $find = function (string $pattern) use ($files): ?DirectoryEntry {
            foreach ($files as [$entry, $suffix]) {
                if (preg_match($pattern, $suffix)) {
                    return $entry;
                }
            }

            return null;
        };
        $at = fn (string $status, DirectoryEntry $file, ?DirectoryEntry $basis = null) => [
            'status' => $status, 'file' => $file->name, 'size' => $file->size, 'basis' => $basis?->size, 'mtime' => $file->mtime,
        ];

        $mp4 = $find('/^\.mp4$/');
        if ($temp = $find('/^-temp(-\d+)?\.mp4$/')) {
            return $at(self::TAGGING, $temp, $mp4);
        }
        $source = $find('/^\.(hls|dash)\.ts$/');
        if ($partial = $find('/^\.partial\.mp4$/')) {
            return $at(self::CONVERTING, $partial, $source);
        }
        if ($source) {
            return ['status' => self::CONVERTING, 'file' => $source->name, 'size' => 0.0, 'basis' => $source->size, 'mtime' => $source->mtime];
        }
        if ($audio = $find('/^\.audio\.(m4a|ts)$/')) {
            return $at(self::AUDIO, $audio);
        }
        if ($video = $find('/^\.video\.(ts|m4v)$/')) {
            return $at(self::DOWNLOADING, $video);
        }
        if ($mp4) {
            // Cover art sits beside the .mp4 only until tagging finishes.
            $art = $find('/^\.jpg$/');

            return $art ? ['status' => self::TAGGING, 'file' => $mp4->name, 'size' => null, 'basis' => null, 'mtime' => max($mp4->mtime, $art->mtime)] : $at(self::COMPLETED, $mp4);
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
