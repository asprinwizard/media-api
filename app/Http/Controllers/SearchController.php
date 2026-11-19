<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use App\Library\ActivityLogger;
use App\Library\FileType;
use App\Support\Paths\InvalidMediaPath;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;
use App\Support\Shell\LocalShell;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Library search (port of search/xhr-do-search.php).
 *
 * Greps the pre-built index files (one library-relative path per line).
 * -F: the term is a plain string, never a regex or a shell fragment.
 * -h: no "file:" prefix, so paths containing ":" come through intact.
 */
class SearchController extends Controller
{
    public function __invoke(
        Request $request,
        MediaUser $user,
        LocalShell $shell,
        MediaPathResolver $paths,
        ActivityLogger $activity,
    ): JsonResponse {
        $query = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']])['q']);
        abort_if(mb_strlen($query) < 2, 422, 'Enter at least two characters.');

        $activity->search($user->username, $query);

        $result = $shell->run(['grep', '-r', '-i', '-h', '-F', '--', $query, config('media.search_index')], timeout: 60);
        // grep: 0 = matches, 1 = no matches, 2 = error
        abort_if($result->exitCode() > 1, 500, 'The search index could not be read.');

        $limit = config('media.search_limit');
        $results = [];
        $truncated = false;
        $seen = [];

        foreach (preg_split('/\R/', $result->output(), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            try {
                $path = $paths->library($line, $user);
            } catch (InvalidMediaPath) {
                continue; // restricted folder for this user, or a malformed line
            }
            if ($path->isRoot() || isset($seen[$path->relative])) {
                continue;
            }
            if (count($results) >= $limit) {
                $truncated = true;
                break;
            }
            $seen[$path->relative] = true;
            $results[] = [
                'name' => $path->name(),
                'path' => $path->relative,
                'type' => $this->type($path)->value,
            ];
        }

        return response()->json([
            'query' => $query,
            'results' => $results,
            'truncated' => $truncated,
        ]);
    }

    /**
     * Known media extensions are files; anything else is checked on disk
     * (as the old search did), which tells folders from other files.
     */
    private function type(MediaPath $path): FileType
    {
        $type = FileType::fromFileName($path->name());
        if ($type !== FileType::File) {
            return $type;
        }

        return is_dir($path->absolute()) ? FileType::Folder : FileType::File;
    }
}
