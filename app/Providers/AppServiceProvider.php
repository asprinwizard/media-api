<?php

namespace App\Providers;

use App\Acquire\Iplayer\IplayerService;
use App\Acquire\Upload\ChunkedUploads;
use App\Acquire\Youtube\YoutubeService;
use App\Admin\Convert\CloudConvertConverter;
use App\Admin\Convert\Converter;
use App\Admin\CopyQueue;
use App\Admin\Extract\ArchiveExtractor;
use App\Admin\Extract\ExtractJobs;
use App\Auth\HtgroupRepository;
use App\Library\CastLinks;
use App\Library\DirectoryReader;
use App\Ops\DriveReport;
use App\Ops\LatestFiles;
use App\Ops\LiveTv;
use App\Ops\LogViewer;
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

        $this->app->bind(ChunkedUploads::class, fn ($app) => new ChunkedUploads(
            $app->make(LocalShell::class),
            $app->make(MediaPathResolver::class),
            config('media.upload.chunks_dir'),
            config('media.upload.stale_hours'),
        ));
        $this->app->bind(IplayerService::class, fn ($app) => new IplayerService(
            $app->make(LocalShell::class),
            $app->make(DirectoryReader::class),
            array_values(config('media.iplayer.command')),
            config('media.iplayer.download_dir'),
            config('media.iplayer.public_dir'),
            config('media.iplayer.queue_script'),
            config('media.iplayer.logs'),
            config('media.iplayer.sd_copy_args'),
            config('media.iplayer.search_timeout'),
        ));
        $this->app->bind(YoutubeService::class, fn ($app) => new YoutubeService(
            $app->make(LocalShell::class),
            config('media.youtube.binary'),
            config('media.youtube.cookies'),
            config('media.youtube.download_dir'),
            config('media.youtube.log'),
            config('media.youtube.info_timeout'),
        ));

        $this->app->bind(LiveTv::class, fn ($app) => new LiveTv(
            $app->make(LocalShell::class),
            new RemoteShell($app->make(LocalShell::class), config('media.live_tv.ssh'), config('media.live_tv.ssh_user').'@'.config('media.live_tv.host')),
            config('media.live_tv.host'),
            config('media.live_tv.check_port'),
            config('media.live_tv.mac'),
            config('media.live_tv.wakeonlan'),
        ));
        $this->app->bind(LogViewer::class, fn ($app) => new LogViewer($app->make(LocalShell::class), config('media.logs')));
        $this->app->bind(DriveReport::class, fn ($app) => new DriveReport(
            $app->make(LocalShell::class),
            $app->make('shell.media01'),
            config('media.tools.link_folders'),
            config('media.paths.library'),
            config('media.tools.hdparm'),
            config('media.tools.hdparm_sudo'),
            config('media.media01.root'),
            config('media.tools.media01_hdparm'),
        ));
        $this->app->bind(LatestFiles::class, fn ($app) => new LatestFiles(
            $app->make(LocalShell::class),
            $app->make('shell.media01'),
            config('media.paths.library'),
            config('media.media01.root'),
            config('media.tools.latest_cache_dir'),
            config('media.tools.latest_keep'),
            config('media.tools.latest_cache_minutes'),
            config('media.tools.latest_timeout'),
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
