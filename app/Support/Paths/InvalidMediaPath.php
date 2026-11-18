<?php

namespace App\Support\Paths;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rendered as JSON by Laravel's handler: 400 for malformed input,
 * 403 for paths the user may not reach.
 */
class InvalidMediaPath extends HttpException
{
    public static function badPath(string $reason): self
    {
        return new self(400, "Invalid path: {$reason}.");
    }

    public static function badName(string $name): self
    {
        return new self(400, "\"{$name}\" isn't a valid file or folder name.");
    }

    public static function forbidden(): self
    {
        return new self(403, 'You don\'t have access to this folder.');
    }

    public static function outsideRoot(): self
    {
        return new self(403, 'That path points outside the media library.');
    }
}
