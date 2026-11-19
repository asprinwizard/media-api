<?php

namespace App\Console\Commands;

use App\Admin\Extract\ArchiveExtractor;
use App\Admin\Extract\ExtractionFailed;
use App\Admin\Extract\ExtractJobs;
use Illuminate\Console\Command;

/**
 * Runs one extraction job in the background (started by the admin API).
 */
class ExtractArchive extends Command
{
    protected $signature = 'media:extract {id : Job id from the extract request}';

    protected $description = 'Extract an archive for the admin Extract action';

    public function handle(ExtractJobs $jobs, ArchiveExtractor $extractor): int
    {
        $id = (string) $this->argument('id');
        $job = $jobs->get($id);
        if ($job === null || ($job['status'] ?? '') !== 'queued') {
            $this->error('No queued job with that id.');

            return self::FAILURE;
        }

        $jobs->update($id, ['status' => 'running', 'started' => time()]);

        try {
            $extractor->extract($job['archive'], $job['destination']);
            $jobs->update($id, ['status' => 'done', 'finished' => time()]);

            return self::SUCCESS;
        } catch (ExtractionFailed $e) {
            $jobs->update($id, ['status' => 'failed', 'error' => $e->getMessage(), 'finished' => time()]);

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $jobs->update($id, ['status' => 'failed', 'error' => 'Extraction failed unexpectedly.', 'finished' => time()]);

            return self::FAILURE;
        }
    }
}
