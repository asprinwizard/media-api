<?php

namespace App\Admin\Convert;

use CloudConvert\CloudConvert;
use CloudConvert\Models\Job;
use CloudConvert\Models\Task;

/**
 * CloudConvert v2 (port of lib/CloudConvertV2.php): import over SFTP from
 * media02, convert with the preset's options, export back over SFTP.
 */
class CloudConvertConverter implements Converter
{
    /**
     * @param  array{host: ?string, port: int, username: ?string, password: ?string}  $sftp
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly array $sftp,
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->sftp['username']) && ! empty($this->sftp['password']);
    }

    public function start(string $source, string $export, array $options, string $tag): string
    {
        $import = $this->withSftp(new Task('import/sftp', 'import-1'))->set('file', $source);

        $convert = new Task('convert', 'convert-1');
        $convert->set('input', ['import-1']);
        foreach ($options as $key => $value) {
            $convert->set($key, $value);
        }

        $exportTask = $this->withSftp(new Task('export/sftp', 'export-1'))
            ->set('input', ['convert-1'])
            ->set('file', $export);

        $job = (new Job)
            ->setTag($tag)
            ->addTask($import)
            ->addTask($convert)
            ->addTask($exportTask);

        $this->client()->jobs()->create($job);

        return (string) $job->getId();
    }

    public function recent(int $limit = 20): array
    {
        $jobs = $this->client()->jobs()->all(['per_page' => $limit]);
        $out = [];
        foreach ($jobs as $job) {
            /** @var Job $job */
            $created = $job->getCreatedAt();
            $out[] = [
                'id' => (string) $job->getId(),
                'tag' => $job->getTag(),
                'status' => (string) $job->getStatus(),
                'created' => $created instanceof \DateTimeInterface ? $created->getTimestamp() : null,
            ];
        }

        return array_slice($out, 0, $limit);
    }

    private function withSftp(Task $task): Task
    {
        return $task
            ->set('host', $this->sftp['host'])
            ->set('port', $this->sftp['port'])
            ->set('username', $this->sftp['username'])
            ->set('password', $this->sftp['password']);
    }

    private function client(): CloudConvert
    {
        return new CloudConvert(['api_key' => $this->apiKey]);
    }
}
