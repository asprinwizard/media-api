<?php

namespace App\Library;

use App\Auth\MediaUser;
use App\Support\Paths\MediaPath;
use App\Support\Paths\MediaPathResolver;

/**
 * Folder listings for the Library screen (port of modules/library.php).
 */
class LibraryService
{
    public function __construct(
        private readonly DirectoryReader $reader,
        private readonly MediaPathResolver $paths,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listing(MediaPath $folder, MediaUser $user, string $sort = 'name', string $direction = 'asc'): array
    {
        $entries = $this->reader->read($folder->absolute());

        if ($folder->isRoot()) {
            $entries = array_values(array_filter(
                $entries,
                fn (DirectoryEntry $e) => $this->paths->canSeeTopLevel($e->name, $user),
            ));
        }

        return [
            'path' => $folder->relative,
            'name' => $folder->isRoot() ? 'Video' : $folder->name(),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'alpha' => $sort === 'name' && in_array($folder->name(), config('media.alpha_folders'), true),
            'sort' => $sort,
            'dir' => $direction,
            'items' => array_map(fn (DirectoryEntry $e) => [
                'name' => $e->name,
                'path' => $folder->isRoot() ? $e->name : $folder->relative.'/'.$e->name,
                'type' => $e->type->value,
                'size' => $e->size,
                'mtime' => $e->mtime,
                'letter' => SortKey::letter($e->name),
            ], SortKey::sort($entries, $sort, $direction)),
        ];
    }

    /**
     * Playable files in the same folder, for the player's "also in this folder".
     *
     * @return list<array{name: string, path: string}>
     */
    public function playableSiblings(MediaPath $file): array
    {
        $folder = $file->parent();
        $entries = array_filter(
            $this->reader->read($folder->absolute()),
            fn (DirectoryEntry $e) => $e->type->isPlayable(),
        );

        return array_map(fn (DirectoryEntry $e) => [
            'name' => $e->name,
            'path' => $folder->isRoot() ? $e->name : $folder->relative.'/'.$e->name,
        ], SortKey::sort(array_values($entries)));
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function breadcrumbs(MediaPath $folder): array
    {
        $crumbs = [['name' => 'Video', 'path' => '']];
        $path = '';
        foreach ($folder->segments() as $segment) {
            $path = $path === '' ? $segment : "$path/$segment";
            $crumbs[] = ['name' => $segment, 'path' => $path];
        }

        return $crumbs;
    }
}
