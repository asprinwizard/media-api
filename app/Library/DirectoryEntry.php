<?php

namespace App\Library;

final readonly class DirectoryEntry
{
    public function __construct(
        public string $name,
        public FileType $type,
        /** Bytes. A float, because PHP ints are 32-bit on media02 and files exceed 2 GB. */
        public ?float $size,
        public int $mtime,
    ) {}
}
