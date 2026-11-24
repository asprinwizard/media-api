<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Convert\Converter;
use App\Admin\Extract\ArchiveExtractor;
use App\Admin\Extract\ExtractJobs;
use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use App\Support\Paths\InvalidMediaPath;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use App\Support\Shell\LocalShell;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin Extract (background job) and Convert (CloudConvert).
 * Ports of admin_panel/xhr_extract_file.php and xhr_convert_file.php.
 */
class JobsController extends Controller
{
    public function __construct(private readonly MediaPathResolver $paths) {}

    /**
     * Start extracting an archive on media02. Destination defaults to the
     * archive's folder; an optional new sub-folder is created on the way.
     */
    public function startExtract(Request $request, MediaUser $user, ExtractJobs $jobs, LocalShell $shell): JsonResponse
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:4096'],
            'newFolder' => ['nullable', 'string', 'max:255'],
        ]);

        $archive = $this->paths->library($input['path'] ?? '', $user)->child($input['name']);
        abort_unless(is_file($archive->absolute()), 422, 'That archive isn\'t in this folder.');
        abort_unless(ArchiveExtractor::supports($archive->name()), 422, 'Only zip, rar, tar, gz, tgz and 7z files can be extracted.');

        $destination = $this->paths->library($input['destination'] ?? ($input['path'] ?? ''), $user);
        if (! empty($input['newFolder'])) {
            $destination = $this->paths->library(MediaPath::joinSubfolder($destination->relative, $input['newFolder']), $user);
        }

        $id = $jobs->create([
            'archive' => $archive->absolute(),
            'archiveName' => $archive->name(),
            'destination' => $destination->absolute(),
            'destinationPath' => $destination->relative,
            'user' => $user->username,
        ]);

        $shell->background(
            [config('media.php_binary'), base_path('artisan'), 'media:extract', $id],
            storage_path('logs/extract.log'),
        );
        Log::info('admin.extract', ['user' => $user->username, 'archive' => $archive->relative, 'destination' => $destination->relative, 'job' => $id]);

        return response()->json($jobs->get($id), 202);
    }

    public function extractStatus(string $id, ExtractJobs $jobs): JsonResponse
    {
        $job = $jobs->get($id);
        abort_if($job === null, 404, 'No such extraction.');

        return response()->json(array_intersect_key($job, array_flip([
            'id', 'status', 'archiveName', 'destinationPath', 'error', 'created', 'started', 'finished',
        ])));
    }

    public function presets(Converter $converter): JsonResponse
    {
        return response()->json([
            'configured' => $converter->isConfigured(),
            'presets' => collect(config('media.convert.presets'))
                ->map(fn (array $preset, string $key) => [
                    'key' => $key,
                    'label' => $preset['label'],
                    'input' => $preset['options']['input_format'] ?? null,
                ])->values(),
        ]);
    }

    /**
     * Start one CloudConvert job per selected file.
     */
    public function startConvert(Request $request, MediaUser $user, Converter $converter): JsonResponse
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'names' => ['required', 'array', 'min:1', 'max:50'],
            'names.*' => ['required', 'string', 'max:255'],
            'preset' => ['required', 'string', 'in:'.implode(',', array_keys(config('media.convert.presets')))],
        ]);
        abort_unless($converter->isConfigured(), 503, 'Convert isn\'t set up yet: add the CloudConvert API key and SFTP login to the server\'s .env.');

        $preset = config('media.convert.presets.'.$input['preset']);
        $folder = $this->paths->library($input['path'] ?? '', $user);
        $exportRoot = rtrim(config('media.convert.export_root'), '/');

        $results = [];
        foreach ($input['names'] as $name) {
            try {
                $file = $folder->child($name);
                if (! is_file($file->absolute())) {
                    throw new \RuntimeException('It isn\'t a file in this folder.');
                }
                $wanted = $preset['options']['input_format'] ?? null;
                if ($wanted && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $wanted) {
                    throw new \RuntimeException("This preset only converts .{$wanted} files.");
                }

                // As before: results mirror the source path, without "Uploaded Files/"
                $relative = preg_replace('#^Uploaded Files/#', '', $file->relative);
                $id = $converter->start(
                    $file->absolute(),
                    $exportRoot.'/'.$relative,
                    $preset['options'],
                    $input['preset'].'-'.pathinfo($name, PATHINFO_FILENAME),
                );
                $results[] = ['name' => $name, 'ok' => true, 'job' => $id];
            } catch (InvalidMediaPath|\RuntimeException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['name' => $name, 'ok' => false, 'error' => 'CloudConvert refused the job: '.mb_substr($e->getMessage(), 0, 200)];
            }
        }

        Log::info('admin.convert', ['user' => $user->username, 'path' => $folder->relative, 'preset' => $input['preset'], 'results' => $results]);
        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return response()->json(['ok' => $failed === 0, 'failed' => $failed, 'results' => $results]);
    }

    public function convertJobs(Converter $converter): JsonResponse
    {
        abort_unless($converter->isConfigured(), 503, 'Convert isn\'t set up yet.');

        try {
            $jobs = $converter->recent(20);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'CloudConvert couldn\'t be reached.'], 502);
        }

        $active = count(array_filter($jobs, fn ($j) => in_array($j['status'], ['waiting', 'processing'], true)));

        return response()->json(['jobs' => $jobs, 'active' => $active > 0]);
    }
}
