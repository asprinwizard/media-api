<?php

namespace App\Admin;

/**
 * The hourly "copy to media01" queue (port of xhr_queue_file_copy.php).
 *
 * Lines are appended to the queue file, which root's cron runs with bash
 * (media-web/scripts/cron/copy_file_queue, unchanged). Because root runs
 * them, every value is quoted for the exact number of shells that parse it:
 *
 *   ssh … <cmd>   local bash parses the line, then media01's shell parses
 *                 <cmd>: quote the path, then quote the whole command again.
 *   rsync …       only local bash parses it; --protect-args stops media01's
 *                 shell re-parsing the remote path: quote each argument once.
 */
class CopyQueue
{
    public function __construct(
        private readonly string $queueFile,
        private readonly string $sshTarget,   // root@media01
        private readonly string $remoteRoot,  // /sharedfolders/Video
    ) {}

    /**
     * @param  list<array{source: string, name: string}>  $files  source = absolute local path
     * @param  string  $destination  media01 library-relative folder
     * @return list<string> the lines added
     */
    public function queue(array $files, string $destination): array
    {
        $remoteDir = rtrim($this->remoteRoot, '/').($destination === '' ? '' : '/'.$destination);

        $lines = [
            'ssh -p 22 '.escapeshellarg($this->sshTarget).' '.escapeshellarg('mkdir -p -m 777 -- '.escapeshellarg($remoteDir)),
        ];

        foreach ($files as $file) {
            $lines[] = implode(' ', [
                'rsync -avzL --recursive --chown=root:users',
                escapeshellarg('--out-format=%t %n%L'),
                escapeshellarg('--rsh=ssh -p22'),
                '--protect-args --keep-dirlinks --',
                escapeshellarg($file['source']),
                escapeshellarg($this->sshTarget.':'.$remoteDir.'/'.$file['name']),
            ]);
        }

        $handle = fopen($this->queueFile, 'a');
        if ($handle === false) {
            throw new \RuntimeException('The copy queue file can\'t be written.');
        }
        try {
            flock($handle, LOCK_EX);
            fwrite($handle, implode("\n", $lines)."\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /**
     * @return array{pending: list<string>, running: list<string>}
     */
    public function contents(): array
    {
        $read = fn (string $file) => is_readable($file)
            ? array_values(array_filter(file($file, FILE_IGNORE_NEW_LINES) ?: [], 'strlen'))
            : [];

        return [
            'pending' => $read($this->queueFile),
            'running' => $read($this->queueFile.'_active'),
        ];
    }

    /**
     * Old behaviour: copied "… (SD).mp4" files lose the " (SD)" on media01.
     */
    public static function destinationName(string $name): string
    {
        return str_ends_with($name, ' (SD).mp4') ? substr($name, 0, -strlen(' (SD).mp4')).'.mp4' : $name;
    }
}
