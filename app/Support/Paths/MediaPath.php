<?php

namespace App\Support\Paths;

/**
 * A path relative to one of the media roots (library, upload, media01 ...).
 *
 * Instances are only made through MediaPathResolver, which validates them,
 * so code holding a MediaPath can trust it has no ".." segments.
 * Relative form is "TV/Drama/Show" (no leading or trailing slash; "" = root).
 */
final readonly class MediaPath
{
    public function __construct(
        public string $root,
        public string $relative,
    ) {}

    /**
     * Normalise a client-supplied relative path, or throw.
     *
     * The value must already be URL-decoded exactly once (Laravel does that
     * for query and body parameters); it is NOT decoded again here, so
     * "%2e%2e" stays a literal folder name and can't become "..".
     */
    public static function normalise(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw InvalidMediaPath::badPath('contains a NUL byte');
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw InvalidMediaPath::badPath('contains ".."');
            }
            if (str_starts_with($segment, '.')) {
                throw InvalidMediaPath::hidden(); // hidden files and folders are never served
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * The relative path of a typed "new sub-folder" under $base. It may be a
     * path such as "Show/Series 1 (2026)"; spaces around each part are trimmed.
     * The result is NOT validated: pass it back through MediaPathResolver,
     * which refuses ".." and hidden folders and checks containment.
     */
    public static function joinSubfolder(string $base, string $subfolder): string
    {
        $parts = array_map('trim', explode('/', str_replace('\\', '/', $subfolder)));

        return implode('/', array_filter([$base, ...$parts], fn (string $part) => $part !== ''));
    }

    /**
     * Validate a single file or folder name (for create / rename).
     */
    public static function assertValidName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw InvalidMediaPath::badName($name);
        }
        if (str_starts_with($name, '.')) {
            throw new InvalidMediaPath(400, "Names can't start with a dot: \"{$name}\" would be hidden.");
        }
    }

    public function absolute(): string
    {
        return $this->relative === '' ? $this->root : $this->root.'/'.$this->relative;
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->relative === '' ? [] : explode('/', $this->relative);
    }

    public function name(): string
    {
        $segments = $this->segments();

        return $segments === [] ? '' : end($segments);
    }

    public function isRoot(): bool
    {
        return $this->relative === '';
    }

    public function parent(): self
    {
        $segments = $this->segments();
        array_pop($segments);

        return new self($this->root, implode('/', $segments));
    }

    public function child(string $name): self
    {
        self::assertValidName($name);

        return new self($this->root, $this->relative === '' ? $name : $this->relative.'/'.$name);
    }
}
