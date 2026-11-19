<?php

namespace App\Http\Controllers;

use App\Auth\MediaUser;
use App\Library\LibraryService;
use App\Support\Paths\MediaPathResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function __invoke(Request $request, MediaUser $user, MediaPathResolver $paths, LibraryService $library): JsonResponse
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:4096'],
            'sort' => ['nullable', 'in:name,date'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $folder = $paths->library($input['path'] ?? '', $user);
        abort_unless(is_dir($folder->absolute()), 404, 'Folder not found.');

        return response()->json($library->listing($folder, $user, $input['sort'] ?? 'name', $input['dir'] ?? 'asc'));
    }
}
