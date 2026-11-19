<?php

namespace App\Admin;

/**
 * Suggested tidy names for downloaded files (port of Site::smartFormatName).
 *
 * Mainly for get_iplayer names like
 *   "Harbour_Lights____s02e05_-_Undertow---m002x4kq.mp4"  ->  "S02E05 - Undertow.mp4"
 * and release names with quality tags ("720p", "x264", "BluRay" …).
 *
 * Behaviour matches the old function, except that the programme name is
 * regex-escaped (the old code broke on names containing "(" or "+").
 */
final class SmartFileNamer
{
    private const NOISE = [
        '/720p/i', '/720hd/i', '/1080p/i', '/1080hd/i', '/bluray/i', '/x264/i', '/divx/i',
        '/xvid/i', '/yify/i', '/dvdrip/i', '/torrent/i', '/hdrip/i', '/FRENCH/', '/webrip/i',
    ];

    public static function suggest(string $name): string
    {
        $separator = strpos($name, '____');
        $programme = $separator === false ? '' : substr($name, 0, $separator);

        $patterns = [];
        $replacements = [];
        if ($programme !== '') {
            $patterns[] = '/'.preg_quote($programme, '/').'/';
            $replacements[] = '';
        }
        array_push($patterns, '/_default/i', '/_/', '/---([A-Za-z0-9]+)\./', '/\.(?=.*?\.)/');
        array_push($replacements, '', ' ', '.', ' ');
        foreach (self::NOISE as $word) {
            $patterns[] = $word;
            $replacements[] = '';
        }
        $patterns[] = '/\s\s+/';
        $replacements[] = '';

        $smart = preg_replace($patterns, $replacements, $name) ?? $name;
        $withoutExtension = self::withoutExtension($smart);

        $seriesPrefix = substr($smart, 0, 1);
        $seriesNum = substr($smart, 1, 2);
        $episodePrefix = substr($smart, 3, 1);
        $episodeNum = substr($smart, 4, 2);

        // s01e01 -> S01E01
        if ($seriesPrefix === 's' && $episodePrefix === 'e' && is_numeric($seriesNum) && is_numeric($episodeNum)) {
            $smart = 'S'.$seriesNum.'E'.$episodeNum.substr($smart, 6);
        }

        // Drop non-descriptive "Episode N" titles after series 1
        $episodeName = substr($withoutExtension, 9);
        if ($seriesNum !== '01' && strtolower(substr($episodeName, 0, 8)) === 'episode ') {
            $smart = preg_replace('/\ \-\ episode\ ([A-Za-z0-9]+)\./i', '.', $smart) ?? $smart;
        }

        // Films and one-offs: fall back to / prepend the programme name
        if (strlen($withoutExtension) <= 11) {
            $extension = pathinfo($smart, PATHINFO_EXTENSION);
            $readable = str_replace('_', ' ', $programme);
            if ($withoutExtension === '') {
                $smart = $readable.'.'.$extension;
            } elseif ($withoutExtension === '(SD)') {
                $smart = $readable.' '.$withoutExtension.'.'.$extension;
            } elseif (str_contains($withoutExtension, 's01e01')) {
                $smart = $readable.' - '.strtoupper($withoutExtension).'.'.$extension;
            }
        }

        return $smart;
    }

    private static function withoutExtension(string $name): string
    {
        return preg_replace('/\.[^.]+$/', '', $name) ?? $name;
    }
}
