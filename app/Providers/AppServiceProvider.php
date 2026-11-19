<?php

namespace App\Providers;

use App\Admin\Convert\CloudConvertConverter;
use App\Admin\Convert\Converter;
use App\Admin\CopyQueue;
use App\Admin\Extract\ArchiveExtractor;
use App\Admin\Extract\ExtractJobs;
use App\Auth\HtgroupRepository;
use App\Library\CastLinks;
use App\Library\DirectoryReader;
use App\Servers\LocalMediaServer;
use App\Servers\Media01Server;
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

        $this->app->bind(LocalMediaServer::class, fn ($app) => new LocalMediaServer(
            config('media.paths.library'),
            $app->make(LocalShell::class),
            $app->make(DirectoryReader::class),
        ));

        $this->app->bind(Media01Server::class, fn ($app) => new Media01Server(
            $app->make('shell.media01'),
            config('media.media01.root'),
        ));

        $this->app->bind(CopyQueue::class, fn () => new CopyQueue(
            config('media.copy_queue'),
            config('media.media01.user').'@'.config('media.media01.host'),
            config('media.media01.root'),
        ));

        $this->app->bind(ExtractJobs::class, fn () => new ExtractJobs(config('media.extract.jobs_path')));
        $this->app->bind(ArchiveExtractor::class, fn ($app) => new ArchiveExtractor(
            $app->make(LocalShell::class),
            config('media.extract.timeout'),
        ));
        $this->app->bind(Converter::class, fn () => new CloudConvertConverter(
            config('services.cloudconvert.key'),
            config('services.sftp'),
        ));

        $this->app->bind(CastLinks::class, fn () => new CastLinks(
            config('media.cast.path'),
            config('media.cast.url_prefix'),
            config('media.cast.ttl_hours'),
        ));

        $this->app->bind('shell.media01', fn ($app) => new RemoteShell(
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
