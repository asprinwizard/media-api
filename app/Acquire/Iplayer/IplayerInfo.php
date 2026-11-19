<?php

namespace App\Acquire\Iplayer;

/**
 * Parsed `get-iplayer --info` output (the cached <pid>/fileinfo file).
 * Port of Iplayer::_parseFileInfo / getTitle / getFileSize.
 *
 * Lines look like "key:      value"; a value of "sub: value" (sub in lower
 * case) becomes a nested key, e.g. qualitysizes => [original => "fhd=…,hd=…"].
 */
final class IplayerInfo
{
    /** Expected-size preference, best first (as before). */
    private const MODE_ORDER = ['fhd', 'hd', 'sd', 'web', 'mobile'];

    /** For SD copies: the standard-definition size, then smaller ones. */
    private const SD_MODE_ORDER = ['sd', 'web', 'mobile'];

    /**
     * @param  array<string, string|array<string, string>>  $fields
     */
    private function __construct(public readonly array $fields) {}

    public static function parse(string $text): self
    {
        $fields = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (! preg_match('/^([a-z][a-z0-9_]*):\s+(.*)$/', rtrim($line), $m)) {
                continue;
            }
            [, $key, $value] = $m;
            $sub = explode(': ', $value, 2);
            if (count($sub) === 2 && ctype_lower(substr($sub[0], 0, 1)) && preg_match('/^[a-z0-9_]+$/', $sub[0])) {
                $existing = $fields[$key] ?? [];
                $fields[$key] = (is_array($existing) ? $existing : []) + [$sub[0] => $sub[1]];
            } else {
                $fields[$key] = $value;
            }
        }

        return new self($fields);
    }

    public function title(): ?string
    {
        $title = $this->fields['title'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * Expected size in bytes of the best mode listed under qualitysizes/original
     * (falling back to modesizes), or null when unknown. $sdCopy picks the
     * standard-definition size instead.
     */
    public function expectedSize(bool $sdCopy = false): ?float
    {
        // "qualitysizes: original: fhd=…" (as the old code read it), else
        // modesizes in either shape.
        $raw = null;
        foreach (['qualitysizes', 'modesizes'] as $key) {
            $value = $this->fields[$key] ?? null;
            $raw = is_array($value) ? ($value['original'] ?? reset($value)) : $value;
            if (is_string($raw) && str_contains($raw, '=')) {
                break;
            }
            $raw = null;
        }
        if ($raw === null) {
            return null;
        }

        $sizes = [];
        foreach (explode(',', $raw) as $pair) {
            [$mode, $size] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($mode !== null && $size !== null) {
                $sizes[strtolower(trim($mode))] = self::bytes($size);
            }
        }
        foreach ($sdCopy ? self::SD_MODE_ORDER : self::MODE_ORDER as $mode) {
            if (! empty($sizes[$mode])) {
                return $sizes[$mode];
            }
        }

        return null;
    }

    /** "1234MB", "1.2 GB", "850kB", "123456" (bytes) → bytes. */
    public static function bytes(string $value): ?float
    {
        if (! preg_match('/^\s*([\d.]+)\s*([kKmMgGtT]?)i?[bB]?\s*$/', $value, $m)) {
            return null;
        }
        $power = ['' => 0, 'k' => 1, 'm' => 2, 'g' => 3, 't' => 4][strtolower($m[2])];

        return (float) $m[1] * (1024 ** $power);
    }
}
