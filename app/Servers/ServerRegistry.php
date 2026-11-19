<?php

namespace App\Servers;

use App\Auth\MediaUser;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Validation\ValidationException;

/**
 * Picks the server for a request and validates paths for it.
 */
class ServerRegistry
{
    public const SERVERS = ['media02', 'media01'];

    public function __construct(
        private readonly LocalMediaServer $media02,
        private readonly Media01Server $media01,
        private readonly MediaPathResolver $paths,
    ) {}

    public function get(string $name): MediaServer
    {
        return match ($name) {
            'media02' => $this->media02,
            'media01' => $this->media01,
            default => throw ValidationException::withMessages(['server' => 'Unknown server.']),
        };
    }

    /**
     * Validated folder path for a server: full checks on media02 (containment,
     * restricted folders), lexical checks on media01.
     */
    public function path(string $server, string $path, MediaUser $user): MediaPath
    {
        return $server === 'media01'
            ? $this->paths->media01($path)
            : $this->paths->library($path, $user);
    }
}
