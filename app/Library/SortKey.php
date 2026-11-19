<?php

namespace App\Library;

/**
 * Library ordering (port of Site::createSortIndex).
 *
 * Folders first, then files; names compared case-insensitively ignoring a
 * leading "The " or "A ". Unlike the old code this never drops entries whose
 * keys collide ("The Office" vs "Office"): the full name breaks ties.
 */
final class SortKey
{
    public static function of(string $name): string
    {
        $lower = mb_strtolower($name);
        foreach (['the ', 'a '] as $article) {
            if (str_starts_with($lower, $article)) {
                return substr($lower, strlen($article));
            }
        }

        return $lower;
    }

    /**
     * The jump-to letter for the A–Z rail and type-to-jump: a–z, 1–9 or "#".
     */
    public static function letter(string $name): string
    {
        $first = mb_substr(self::of($name), 0, 1);

        return preg_match('/^[a-z0-9]$/', $first) ? $first : '#';
    }

    /**
     * @param  list<DirectoryEntry>  $entries
     * @return list<DirectoryEntry>
     */
    public static function sort(array $entries, string $by = 'name', string $direction = 'asc'): array
    {
        usort($entries, function (DirectoryEntry $a, DirectoryEntry $b) use ($by, $direction) {
            $aFolder = $a->type === FileType::Folder;
            $bFolder = $b->type === FileType::Folder;
            if ($aFolder !== $bFolder) {
                return $aFolder ? -1 : 1; // folders first in both directions
            }

            $cmp = $by === 'date' ? $a->mtime <=> $b->mtime : 0;
            $cmp = $cmp ?: strnatcmp(self::of($a->name), self::of($b->name));
            $cmp = $cmp ?: strcmp($a->name, $b->name);

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return $entries;
    }
}
