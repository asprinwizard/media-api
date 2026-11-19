<?php

namespace App\Providers;

use App\Auth\HtgroupRepository;
use App\Library\CastLinks;
use App\Support\Paths\MediaPathResolver;
use App\Support\Shell\LocalShell;
use App\Support\Shell\RemoteShell;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HtgroupRepository::class, fn () => new HtgroupRepository(
            config('media.auth.htgroup_file'),
        ));

        $this->app->singleton(MediaPathResolver::class, fn () => new MediaPathResolver(
            roots: [
                'library' => config('media.paths.library'),
                'upload' => config('media.paths.upload'),
                'media01' => config('media.media01.root'),
            ],
            allowedMounts: config('media.allowed_mounts'),
            restrictedFolders: config('media.restricted_folders'),
        ));

        $this->app->singleton(LocalShell::class);

        $this->app->bind(CastLinks::class, fn () => new CastLinks(
            config('media.cast.path'),
            config('media.cast.url_prefix'),
            config('media.cast.ttl_hours'),
        ));

        $this->app->singleton('shell.media01', fn ($app) => new RemoteShell(
            $app->make(LocalShell::class),
            config('media.media01.ssh'),
            config('media.media01.user').'@'.config('media.media01.host'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
