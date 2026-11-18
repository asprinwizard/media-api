<?php

use App\Auth\HtgroupRepository;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Write a temporary .htgroup file and point config at it.
 */
function useHtgroup(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'htgroup');
    file_put_contents($file, $contents);
    config()->set('media.auth.htgroup_file', $file);
    app()->forgetInstance(HtgroupRepository::class);

    return $file;
}

/**
 * Make a fresh temporary directory and return its real path.
 */
function tempDir(string $prefix = 'media'): string
{
    $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return realpath($dir);
}
