<?php

namespace App\Library;

/**
 * What a library entry is, as the SPA shows it (icon, available actions).
 * Port of media-web's FileFormat lists.
 */
enum FileType: string
{
    case Folder = 'folder';
    case Video = 'video';
    case Audio = 'audio';
    case Subtitle = 'subtitle';
    case Compressed = 'compressed';
    case File = 'file';
    /** A symlink whose target is missing (old site showed a "ban" icon). */
    case Broken = 'broken';

    public static function fromFileName(string $name): self
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '') {
            return self::File;
        }

        foreach (config('media.file_types') as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return self::from($kind);
            }
        }

        return self::File;
    }

    public function isPlayable(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }
}
