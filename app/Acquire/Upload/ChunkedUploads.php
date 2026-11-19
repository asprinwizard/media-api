<?php

namespace App\Acquire\Upload;

use App\Support\Paths\MediaPathResolver;
use App\Support\Shell\LocalShell;

/**
 * Uploads that arrive in pieces, so no single request has to survive a long
 * transfer and file size isn't limited by PHP (32-bit on media02).
 *
 * Each upload gets a working folder (<chunks_dir>/<id>/) holding state.json
 * and the file being assembled (data). Chunks must arrive in order; each one
 * is appended with `cat`, never read into PHP, and sizes are handled as
 * floats (exact for any real file) so nothing overflows past 2 GB.
 *
 * Retries are safe: a chunk that was already stored is accepted again
 * without being appended twice, and if an earlier attempt died part-way
 * through appending, the file is first truncated back to the last good size.
 */
class ChunkedUploads
{
    public function __construct(
        private readonly LocalShell $shell,
        private readonly MediaPathResolver $paths,
        private readonly string $chunksDir,
        private readonly int $staleHours = 24,
    ) {}

    /**
     * @return array{id: string, name: string}
     */
    public function start(string $clientName, float $size, string $user): array
    {
        $name = self::cleanName($clientName);
        $target = $this->paths->upload($name);
        if (file_exists($target->absolute())) {
            throw new UploadRefused("\"{$name}\" is already in the upload folder. Rename it and try again.", 409);
        }

        if (! is_dir($this->chunksDir) && ! @mkdir($this->chunksDir, 0775, true)) {
            throw new UploadRefused('The upload working folder couldn\'t be created on the server.', 500);
        }
        $this->removeStale();

        $free = @disk_free_space($this->chunksDir);
        if ($free !== false && $free < $size + 256 * 1024 * 1024) {
            throw new UploadRefused('There isn\'t enough free space on the server for this file.', 507);
        }

        $id = bin2hex(random_bytes(12));
        mkdir($this->dir($id), 0775);
        touch($this->dir($id).'/data');
        $this->save($id, ['name' => $name, 'size' => $size, 'user' => $user, 'received' => 0, 'bytes' => 0.0, 'created' => time()]);

        return ['id' => $id, 'name' => $name];
    }

    /**
     * Append chunk number $index (0-based) from $chunkFile.
     *
     * @return array{received: int, bytes: float}
     */
    public function append(string $id, int $index, string $chunkFile, string $user): array
    {
        return $this->locked($id, function () use ($id, $index, $chunkFile, $user) {
            $state = $this->load($id, $user);

            if ($index < $state['received']) {
                // A retry of a chunk we already have.
                return ['received' => $state['received'], 'bytes' => $state['bytes']];
            }
            if ($index > $state['received']) {
                throw new UploadRefused("Chunk {$index} arrived before chunk {$state['received']}.", 409, ['expected' => $state['received']]);
            }

            $data = $this->dir($id).'/data';
            $chunkSize = (float) trim($this->shell->run(['stat', '-c', '%s', $chunkFile])->output());
            $good = self::int($state['bytes']);

            // Trim anything a failed earlier attempt left behind, then append.
            $result = $this->shell->run(['sh', '-c', 'truncate -s "$1" "$2" && cat "$3" >> "$2"', 'sh', $good, $data, $chunkFile], 600);
            $now = (float) trim($this->shell->run(['stat', '-c', '%s', $data])->output());
            if (! $result->successful() || abs($now - ($state['bytes'] + $chunkSize)) >= 0.5) {
                $this->shell->run(['truncate', '-s', $good, $data]);
                throw new UploadRefused('The server couldn\'t store that part of the file.', 500);
            }
            if ($now > $state['size'] + 0.5) {
                $this->shell->run(['truncate', '-s', $good, $data]);
                throw new UploadRefused('More data arrived than the file\'s size.', 422);
            }

            $state['received']++;
            $state['bytes'] = $now;
            $this->save($id, $state);

            return ['received' => $state['received'], 'bytes' => $now];
        });
    }

    /**
     * Check the size and move the file into the upload folder.
     *
     * @return array{name: string, size: float}
     */
    public function finish(string $id, string $user): array
    {
        return $this->locked($id, function () use ($id, $user) {
            $state = $this->load($id, $user);
            if (abs($state['bytes'] - $state['size']) >= 0.5) {
                throw new UploadRefused('The upload isn\'t complete yet.', 409, ['expected' => $state['received']]);
            }

            $target = $this->paths->upload($state['name']);
            if (file_exists($target->absolute())) {
                throw new UploadRefused("\"{$state['name']}\" appeared in the upload folder meanwhile. Rename it and try again.", 409);
            }

            // Instant when the working folder is on the same drive; a copy otherwise.
            $moved = $this->shell->run(['mv', '-n', '--', $this->dir($id).'/data', $target->absolute()], 3600);
            if (! $moved->successful() || ! file_exists($target->absolute())) {
                throw new UploadRefused('The finished file couldn\'t be moved into the upload folder.', 500);
            }
            @chmod($target->absolute(), 0664);
            $this->remove($id);

            return ['name' => $state['name'], 'size' => $state['size']];
        });
    }

    public function cancel(string $id, string $user): void
    {
        if (! self::validId($id) || ! is_dir($this->dir($id))) {
            return;
        }
        $this->load($id, $user);
        $this->remove($id);
    }

    /** Client file name → a safe name in the upload folder. */
    public static function cleanName(string $clientName): string
    {
        $name = trim(basename(str_replace('\\', '/', $clientName)));
        if ($name === '' || str_starts_with($name, '.') || preg_match('/[\x00-\x1f]/', $name)) {
            throw new UploadRefused('That file name can\'t be used.', 422);
        }

        return $name;
    }

    /** Whole-number string for shell tools (floats print as 1.0E+9 otherwise). */
    private static function int(float $value): string
    {
        return sprintf('%.0f', $value);
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/', $id);
    }

    private function dir(string $id): string
    {
        return $this->chunksDir.'/'.$id;
    }

    /**
     * @return array{name: string, size: float, user: string, received: int, bytes: float, created: int}
     */
    private function load(string $id, string $user): array
    {
        $file = $this->dir($id).'/state.json';
        if (! self::validId($id) || ! is_file($file)) {
            throw new UploadRefused('That upload has expired or was cancelled. Please start again.', 404);
        }
        $state = json_decode((string) file_get_contents($file), true);
        if (! is_array($state) || ($state['user'] ?? null) !== $user) {
            throw new UploadRefused('That upload belongs to someone else.', 403);
        }
        $state['size'] = (float) $state['size'];
        $state['bytes'] = (float) $state['bytes'];
        $state['received'] = (int) $state['received'];

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function save(string $id, array $state): void
    {
        $file = $this->dir($id).'/state.json';
        file_put_contents("$file.tmp", json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
        rename("$file.tmp", $file);
    }

    /**
     * Run $work holding this upload's lock (chunks for one upload never overlap).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(string $id, callable $work): mixed
    {
        if (! self::validId($id) || ! is_dir($this->dir($id))) {
            throw new UploadRefused('That upload has expired or was cancelled. Please start again.', 404);
        }
        $lock = fopen($this->dir($id).'/lock', 'c');
        if ($lock === false) {
            throw new UploadRefused('The server couldn\'t lock the upload.', 500);
        }
        try {
            flock($lock, LOCK_EX);

            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function remove(string $id): void
    {
        if (self::validId($id)) {
            $this->shell->run(['rm', '-rf', '--', $this->dir($id)]);
        }
    }

    /** Abandoned uploads (no chunk for a day) are cleared out. */
    private function removeStale(): void
    {
        $cutoff = time() - $this->staleHours * 3600;
        foreach (glob($this->chunksDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            $state = "$dir/state.json";
            if (self::validId($id) && (! is_file($state) || filemtime($state) < $cutoff)) {
                $this->remove($id);
            }
        }
    }
}
