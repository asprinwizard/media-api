<?php

namespace App\Admin\Convert;

/**
 * Starts and lists conversion jobs (CloudConvert in production).
 */
interface Converter
{
    public function isConfigured(): bool;

    /**
     * Start one job converting $source (absolute path on media02) to $export
     * (absolute path on media02), both reached over SFTP by the service.
     *
     * @param  array<string, mixed>  $options  convert-task options from the preset
     * @return string the job id
     */
    public function start(string $source, string $export, array $options, string $tag): string;

    /**
     * Recent jobs, newest first.
     *
     * @return list<array{id: string, tag: ?string, status: string, created: ?int}>
     */
    public function recent(int $limit = 20): array;
}
