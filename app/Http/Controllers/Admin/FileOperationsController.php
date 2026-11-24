<?php

namespace App\Http\Controllers\Admin;

use App\Admin\CopyQueue;
use App\Auth\MediaUser;
use App\Http\Controllers\Controller;
use App\Library\LibraryService;
use App\Library\SortKey;
use App\Servers\Media01Server;
use App\Servers\ServerOperationFailed;
use App\Servers\ServerRegistry;
use App\Support\Paths\InvalidMediaPath;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin file and folder operations on media02 and media01
 * (ports of modules/admin_panel/xhr_*.php, rebuilt to work on media01).
 *
 * Batch operations return one result per item, so a failure on one file
 * doesn't hide what happened to the others.
 */
class FileOperationsController extends Controller
{
    public function __construct(private readonly ServerRegistry $servers) {}

    /**
     * Which servers have this folder (media01 is offered only when it does).
     */
    public function servers(Request $request, MediaUser $user, Media01Server $media01): JsonResponse
    {
        $path = $this->servers->path('media02', (string) $request->query('path', ''), $user);

        try {
            $onMedia01 = $media01->exists($path->relative) === 'dir';
            $error = null;
        } catch (ServerOperationFailed $e) {
            $onMedia01 = false;
            $error = $e->getMessage();
        }

        return response()->json(['media02' => true, 'media01' => $onMedia01, 'media01Error' => $error]);
    }

    /**
     * A media01 folder's contents, for browsing media01 in Manage mode.
     */
    public function media01Library(Request $request, MediaUser $user, Media01Server $media01, LibraryService $library): JsonResponse
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'sort' => ['nullable', 'in:name,date'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
        $folder = $this->servers->path('media01', $input['path'] ?? '', $user);

        try {
            $entries = $media01->listing($folder->relative);
        } catch (ServerOperationFailed $e) {
            $status = str_contains($e->getMessage(), 'not on media01') ? 404 : 502;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json($library->present($folder, $entries, $user, $input['sort'] ?? 'name', $input['dir'] ?? 'asc', 'media01'));
    }

    /**
     * Sub-folders, for the cascading destination pickers.
     */
    public function folders(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'server' => ['required', 'in:'.implode(',', ServerRegistry::SERVERS)],
            'path' => ['nullable', 'string', 'max:4096'],
        ]);
        $folder = $this->servers->path($input['server'], $input['path'] ?? '', $user);

        try {
            $names = $this->servers->get($input['server'])->folders($folder->relative);
        } catch (ServerOperationFailed $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'server' => $input['server'],
            'path' => $folder->relative,
            'folders' => array_map(fn (string $name) => [
                'name' => $name,
                'path' => $folder->isRoot() ? $name : $folder->relative.'/'.$name,
                'letter' => SortKey::letter($name),
            ], $names),
        ]);
    }

    public function createFolder(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'server' => ['required', 'in:'.implode(',', ServerRegistry::SERVERS)],
            'path' => ['nullable', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $folder = $this->servers->path($input['server'], $input['path'] ?? '', $user)->child(trim($input['name']));
        $server = $this->servers->get($input['server']);

        try {
            if ($server->exists($folder->relative) !== null) {
                throw new ServerOperationFailed('Something with that name is already there.');
            }
            $server->mkdir($folder->relative);
        } catch (ServerOperationFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit($user, 'create-folder', $input['server'], ['path' => $folder->relative]);

        return response()->json(['path' => $folder->relative], 201);
    }

    /**
     * Move and/or rename files or folders. Without a destination they stay in
     * the same folder (rename only). A new sub-folder can be created on the way.
     */
    public function move(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'server' => ['required', 'in:'.implode(',', ServerRegistry::SERVERS)],
            'path' => ['nullable', 'string', 'max:4096'],
            'destination' => ['nullable', 'string', 'max:4096'],
            'newFolder' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.newName' => ['nullable', 'string', 'max:255'],
        ]);
        set_time_limit(0);

        $serverName = $input['server'];
        $server = $this->servers->get($serverName);
        $source = $this->servers->path($serverName, $input['path'] ?? '', $user);
        $target = array_key_exists('destination', $input) && $input['destination'] !== null
            ? $this->servers->path($serverName, $input['destination'], $user)
            : $source;
        if (! empty($input['newFolder'])) {
            $target = $this->servers->path($serverName, MediaPath::joinSubfolder($target->relative, $input['newFolder']), $user);
        }

        try {
            if ($target->relative !== $source->relative && $server->exists($target->relative) === null) {
                $server->mkdir($target->relative);
            }
        } catch (ServerOperationFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $results = [];
        foreach ($input['items'] as $item) {
            $results[] = $this->attempt($item['name'], function () use ($item, $source, $target, $server) {
                $newName = trim($item['newName'] ?? '') ?: $item['name'];
                $from = $source->child($item['name']);
                $to = $target->child($newName);
                if ($from->relative === $to->relative) {
                    throw new ServerOperationFailed('The new name and place are the same as the old ones.');
                }
                $server->move($from->relative, $to->relative);

                return ['newPath' => $to->relative];
            });
        }

        $this->audit($user, 'move', $serverName, ['from' => $source->relative, 'to' => $target->relative, 'results' => $results]);

        return $this->batch($results);
    }

    public function delete(Request $request, MediaUser $user): JsonResponse
    {
        $input = $request->validate([
            'server' => ['required', 'in:'.implode(',', ServerRegistry::SERVERS)],
            'path' => ['nullable', 'string', 'max:4096'],
            'names' => ['required', 'array', 'min:1', 'max:500'],
            'names.*' => ['required', 'string', 'max:255'],
        ]);
        $server = $this->servers->get($input['server']);
        $folder = $this->servers->path($input['server'], $input['path'] ?? '', $user);

        $results = [];
        foreach ($input['names'] as $name) {
            $results[] = $this->attempt($name, function () use ($name, $folder, $server) {
                $server->delete($folder->child($name)->relative);

                return [];
            });
        }

        $this->audit($user, 'delete', $input['server'], ['path' => $folder->relative, 'results' => $results]);

        return $this->batch($results);
    }

    public function copyQueue(CopyQueue $queue): JsonResponse
    {
        return response()->json($queue->contents());
    }

    /**
     * Queue media02 files for copying to media01 by the hourly cron.
     */
    public function queueCopy(Request $request, MediaUser $user, CopyQueue $queue, MediaPathResolver $paths): JsonResponse
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'destination' => ['required', 'string', 'max:4096'],
            'newFolder' => ['nullable', 'string', 'max:255'],
            'hd' => ['boolean'],
            'files' => ['required', 'array', 'min:1', 'max:500'],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.newName' => ['nullable', 'string', 'max:255'],
        ]);

        $folder = $paths->library($input['path'] ?? '', $user);
        $destination = $paths->media01($input['destination']);
        abort_if($destination->isRoot(), 422, 'Choose a destination folder on media01.');
        if (! empty($input['newFolder'])) {
            $destination = $paths->media01(MediaPath::joinSubfolder($destination->relative, $input['newFolder']));
        }
        if (! empty($input['hd'])) {
            $destination = $destination->child('HD');
        }

        $files = [];
        foreach ($input['files'] as $file) {
            $source = $folder->child($file['name']);
            abort_unless(is_file($source->absolute()), 422, "\"{$file['name']}\" isn't a file in this folder.");
            $name = trim($file['newName'] ?? '') ?: $file['name'];
            MediaPath::assertValidName($name);
            $files[] = ['source' => $source->absolute(), 'name' => CopyQueue::destinationName($name)];
        }

        $lines = $queue->queue($files, $destination->relative);
        $this->audit($user, 'queue-copy', 'media01', ['destination' => $destination->relative, 'files' => count($files)]);

        return response()->json(['queued' => count($files), 'destination' => $destination->relative, 'lines' => $lines] + $queue->contents(), 201);
    }

    /**
     * @return array{name: string, ok: bool, error?: string}
     */
    private function attempt(string $name, callable $operation): array
    {
        try {
            return ['name' => $name, 'ok' => true] + $operation();
        } catch (ServerOperationFailed|InvalidMediaPath $e) {
            return ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function batch(array $results): JsonResponse
    {
        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return response()->json(['ok' => $failed === 0, 'failed' => $failed, 'results' => $results]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(MediaUser $user, string $action, string $server, array $details): void
    {
        Log::info("admin.$action", ['user' => $user->username, 'server' => $server] + $details);
    }
}
