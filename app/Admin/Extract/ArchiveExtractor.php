<?php

namespace App\Admin\Extract;

use App\Support\Shell\LocalShell;

/**
 * Extracts zip, rar, tar, tar.gz/tgz, gz and 7z archives (port of
 * File::extractFile, which never actually extracted zips and had no rar
 * support on media02).
 *
 * Uses `unar` when installed: it handles every format, refuses entries that
 * would write outside the destination, and isn't limited by 32-bit PHP.
 * Without unar, zip and tar formats fall back to tar/ZipArchive; rar fails
 * with a message saying what to install.
 */
class ArchiveExtractor
{
    public const EXTENSIONS = ['zip', 'rar', 'tar', 'gz', 'tgz', '7z'];

    public function __construct(
        private readonly LocalShell $shell,
        private readonly int $timeout,
        private readonly string $unarBinary = 'unar',
    ) {}

    public static function supports(string $fileName): bool
    {
        return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * @throws ExtractionFailed
     */
    public function extract(string $archive, string $destination): void
    {
        if (! is_file($archive)) {
            throw new ExtractionFailed('The archive no longer exists.');
        }
        if (! is_dir($destination) && ! @mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new ExtractionFailed('The destination folder couldn\'t be created.');
        }

        if ($this->hasUnar()) {
            // -D: no extra wrapping folder (as before); -s: skip existing files
            $this->run([$this->unarBinary, '-q', '-D', '-s', '-o', $destination, '--', $archive]);

            return;
        }

        $extension = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
        match ($extension) {
            'tar', 'gz', 'tgz' => $this->run(['tar', '-xf', $archive, '-C', $destination, '--no-same-owner']),
            'zip' => $this->unzip($archive, $destination),
            default => throw new ExtractionFailed('Extracting .'.$extension.' files needs "unar" on the server (apt install unar).'),
        };
    }

    private function hasUnar(): bool
    {
        return $this->shell->run(['sh', '-c', 'command -v "$1"', 'sh', $this->unarBinary], 10)->successful();
    }

    /**
     * @param  list<string>  $argv
     */
    private function run(array $argv): void
    {
        $result = $this->shell->run($argv, $this->timeout);
        if (! $result->successful()) {
            $error = trim($result->errorOutput() ?: $result->output());
            throw new ExtractionFailed($error !== '' ? mb_substr($error, 0, 300) : 'Extraction failed.');
        }
    }

    private function unzip(string $archive, string $destination): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new ExtractionFailed('The zip file couldn\'t be opened.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_starts_with($name, '/') || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $name)) {
                    throw new ExtractionFailed("The zip contains an unsafe path ({$name}), so nothing was extracted.");
                }
            }
            if (! $zip->extractTo($destination)) {
                throw new ExtractionFailed('Extraction failed.');
            }
        } finally {
            $zip->close();
        }
    }
}
