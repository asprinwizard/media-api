<?php

namespace App\Admin\Extract;

/**
 * Background extraction jobs, one small JSON status file each.
 * A request creates the job and starts `artisan media:extract <id>`; the
 * page polls the status. Old status files are removed after a day.
 */
class ExtractJobs
{
    private const ID_PATTERN = '/^[0-9a-f]{24}$/';

    public function __construct(private readonly string $directory) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public function create(array $details): string
    {
        $this->cleanup();
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $id = bin2hex(random_bytes(12));
        $this->write($id, $details + ['id' => $id, 'status' => 'queued', 'created' => time()]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        if (! preg_match(self::ID_PATTERN, $id) || ! is_file($this->file($id))) {
            return null;
        }

        return json_decode((string) file_get_contents($this->file($id)), true) ?: null;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(string $id, array $changes): void
    {
        $job = $this->get($id) ?? ['id' => $id];
        $this->write($id, array_merge($job, $changes, ['updated' => time()]));
    }

    private function write(string $id, array $job): void
    {
        file_put_contents($this->file($id), json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function file(string $id): string
    {
        return rtrim($this->directory, '/')."/{$id}.json";
    }

    private function cleanup(): void
    {
        foreach (glob(rtrim($this->directory, '/').'/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
