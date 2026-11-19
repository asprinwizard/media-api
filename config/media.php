<?php

/*
| Media02 site configuration. Everything that was a constant in media-web's
| lib/ classes lives here, read from .env where it differs per machine.
*/

return [

    'site_title' => env('MEDIA_SITE_TITLE', 'Media02'),

    'auth' => [
        // Shared with Apache's AuthGroupFile. Read-only.
        'htgroup_file' => env('MEDIA_HTGROUP_FILE', '/etc/apache2/media-auth/.htgroup'),

        // Local development only: used when there is no Apache in front.
        // Honoured only if APP_ENV=local, MEDIA_DEV_AUTH=true and the request
        // comes from the loopback interface (see ResolveHtUser).
        'dev' => [
            'enabled' => (bool) env('MEDIA_DEV_AUTH', false),
            'user' => env('MEDIA_DEV_USER'),
            'roles' => array_filter(explode(',', (string) env('MEDIA_DEV_ROLES', ''))),
        ],
    ],

    'roles' => ['admin', 'ftp', 'standard'],

    'paths' => [
        'share' => env('MEDIA_SHARE_PATH', '/home/share'),
        'library' => env('MEDIA_LIBRARY_PATH', '/home/share/Video'),
        'upload' => env('MEDIA_UPLOAD_PATH', '/home/ftp/media'),
        'public' => env('MEDIA_PUBLIC_PATH', '/home/public/media'),
        'private' => env('MEDIA_PRIVATE_PATH', '/home/private/media'),
        'log_dir' => env('MEDIA_LOG_DIR', '/var/log'),
    ],

    // Real directories that library symlinks may point into. MediaPath allows
    // a resolved path only if it is inside one of these (plus the root itself).
    'allowed_mounts' => array_filter(explode(',', (string) env('MEDIA_ALLOWED_MOUNTS', ''))),

    // Folders under the library root that only admins may see or open.
    'restricted_folders' => ['Torrent Downloads', 'Uploaded Files'],

    'alpha_folders' => [
        'Movies', 'Comedy', 'Documentary', 'Drama', 'Music',
        'Sport', 'Football', 'Rugby League', 'Cricket',
    ],

    // Extensions by kind (port of media-web's FileFormat). Lower case.
    'file_types' => [
        'video' => ['avi', 'm2ts', 'mkv', 'mpg', 'mp4', 'mov', 'divx', 'wmv', 'flv', 'webm', 'm4v', 'ts'],
        'audio' => ['mp3', 'wma', 'wav', 'ogg', 'm4a', 'aac'],
        'subtitle' => ['idx', 'sub', 'srt'],
        'compressed' => ['zip', 'gz', 'tar', 'rar'],
    ],

    // Browsers / Chromecast can't play these; cast links download instead.
    'non_streamable_mime' => ['video/x-msvideo', 'video/x-ms-asf'],

    // Pre-built search index: text files, one library-relative path per line.
    'search_index' => env('MEDIA_SEARCH_INDEX', '/home/private/media/search'),
    'search_limit' => (int) env('MEDIA_SEARCH_LIMIT', 1000),

    // Chromecast links: a random folder per link holding a symlink to the
    // file, served by Apache without login at /shared/<token>/<name>.
    // PHP never reads the media (32-bit PHP can't handle files over 2 GB,
    // and mod_xsendfile fails on media02's drives).
    'cast' => [
        'path' => env('MEDIA_CAST_PATH', '/home/public/cast'),
        'url_prefix' => '/shared',
        'ttl_hours' => (int) env('MEDIA_CAST_TTL_HOURS', 24),
    ],

    // Logs shown to admins on the home page (files in paths.log_dir, .log).
    'dashboard_logs' => [
        'media01-tv' => 'TV',
        'media01-movies' => 'Movies',
    ],

    // Lines root's hourly cron runs to copy files to media01 (media-web's
    // scripts/cron/copy_file_queue reads this path; keep them in step).
    'copy_queue' => env('MEDIA_COPY_QUEUE', '/home/public/media/admin/file_copy_queue'),

    // Admin "Convert" (CloudConvert). Presets are CloudConvert convert-task
    // options; results land under export_root mirroring the source path.
    'convert' => [
        'export_root' => env('MEDIA_CONVERT_EXPORT_ROOT', '/home/ftp/media/cloudconvert'),
        'presets' => [
            'reencode' => [
                'label' => 'Re-encode MP4 (H.264, CRF 23, audio copied)',
                'options' => [
                    'input_format' => 'mp4',
                    'output_format' => 'mp4',
                    'engine' => 'ffmpeg_nvidia',
                    'video_codec' => 'x264',
                    'crf' => 23,
                    'preset' => 'medium',
                    'fit' => 'scale',
                    'subtitles_mode' => 'none',
                    'audio_codec' => 'copy',
                ],
            ],
        ],
    ],

    // Admin "Extract": jobs run in the background; status files live here.
    'extract' => [
        'jobs_path' => env('MEDIA_EXTRACT_JOBS', storage_path('app/extract-jobs')),
        'timeout' => (int) env('MEDIA_EXTRACT_TIMEOUT', 3 * 3600),
    ],

    // Upload page (admin, ftp). Files arrive in chunks, appended in a
    // working folder that should be on the same drive as the upload folder
    // (so the final move is instant) and outside it (so it isn't listed).
    'upload' => [
        'chunks_dir' => env('MEDIA_UPLOAD_CHUNKS_DIR', storage_path('app/upload-chunks')),
        'chunk_bytes' => (int) env('MEDIA_UPLOAD_CHUNK_BYTES', 32 * 1024 * 1024),
        'stale_hours' => (int) env('MEDIA_UPLOAD_STALE_HOURS', 24),
    ],

    // BBC iPlayer (admin). get-iplayer runs through sudo, as before; its own
    // options file sets the output folder (download_dir) and filename format.
    'iplayer' => [
        'command' => array_filter(explode(' ', (string) env('MEDIA_IPLAYER_COMMAND', 'sudo get-iplayer'))),
        'download_dir' => env('MEDIA_IPLAYER_DIR', env('MEDIA_UPLOAD_PATH', '/home/ftp/media').'/get_iplayer'),
        // download_queue (+ _active while running) and <pid>/fileinfo, shared with the old site
        'public_dir' => env('MEDIA_IPLAYER_PUBLIC', env('MEDIA_PUBLIC_PATH', '/home/public/media').'/get_iplayer'),
        'queue_script' => base_path('scripts/get_iplayer_download_queue'),
        'logs' => [
            'search' => env('MEDIA_IPLAYER_LOG_DIR', env('MEDIA_LOG_DIR', '/var/log').'/get_iplayer').'/get_iplayer_search.log',
            'download' => env('MEDIA_IPLAYER_LOG_DIR', env('MEDIA_LOG_DIR', '/var/log').'/get_iplayer').'/get_iplayer_download.log',
            'info' => env('MEDIA_IPLAYER_LOG_DIR', env('MEDIA_LOG_DIR', '/var/log').'/get_iplayer').'/get_iplayer_file_info.log',
        ],
        'search_timeout' => (int) env('MEDIA_IPLAYER_SEARCH_TIMEOUT', 300),
        // "SD copy": a second, lower-quality download kept alongside (… (SD)---pid.mp4)
        'sd_copy_args' => ['--force', '--ffmpeg-force', '--fileprefix=<nameshort>____<senum>< - episodeshort> (SD)<---pid>', '--modes=better'],
    ],

    // YouTube (admin), via yt-dlp.
    'youtube' => [
        'binary' => env('MEDIA_YTDLP', 'yt-dlp'),
        'cookies' => env('MEDIA_YOUTUBE_COOKIES', env('MEDIA_PRIVATE_PATH', '/home/private/media').'/youtube/cookies-youtube-com.txt'),
        'download_dir' => env('MEDIA_YOUTUBE_DIR', env('MEDIA_UPLOAD_PATH', '/home/ftp/media').'/youtube'),
        // Not the old site's log file: this one ends with a "finished" marker line.
        'log' => env('MEDIA_YOUTUBE_LOG', env('MEDIA_LOG_DIR', '/var/log').'/yt-dlp/youtube_download.log'),
        'info_timeout' => (int) env('MEDIA_YOUTUBE_INFO_TIMEOUT', 180),
    ],

    'media01' => [
        'host' => env('MEDIA01_HOST', 'media01'),
        'user' => env('MEDIA01_USER', 'root'),
        'ssh' => env('MEDIA01_SSH', 'ssh22'),
        'root' => env('MEDIA01_ROOT', '/sharedfolders/Video'),
    ],

    'php_binary' => env('MEDIA_PHP_BINARY', '/usr/bin/php8.4'),

    // Main navigation, in display order. 'roles' => null means every user.
    // Port of Site::getMainMenu(); the SPA groups items by 'group'.
    'menu' => [
        ['key' => 'home', 'title' => 'Home', 'path' => '/', 'group' => 'browse', 'roles' => null],
        ['key' => 'library', 'title' => 'Library', 'path' => '/library', 'group' => 'browse', 'roles' => null],
        ['key' => 'search', 'title' => 'Search', 'path' => '/search', 'group' => 'browse', 'roles' => null],
        ['key' => 'help', 'title' => 'Help', 'path' => '/help', 'group' => 'browse', 'roles' => null],
        ['key' => 'upload', 'title' => 'Upload', 'path' => '/upload', 'group' => 'get', 'roles' => ['admin', 'ftp']],
        ['key' => 'iplayer', 'title' => 'BBC iPlayer', 'path' => '/iplayer', 'group' => 'get', 'roles' => ['admin']],
        ['key' => 'youtube', 'title' => 'YouTube', 'path' => '/youtube', 'group' => 'get', 'roles' => ['admin']],
        ['key' => 'live-tv', 'title' => 'Live TV', 'path' => '/live-tv', 'group' => 'get', 'roles' => ['admin']],
        ['key' => 'logs', 'title' => 'Logs', 'path' => '/logs', 'group' => 'system', 'roles' => ['admin']],
        ['key' => 'tools', 'title' => 'Tools', 'path' => '/tools', 'group' => 'system', 'roles' => ['admin']],
    ],

    // External links shown on the Tools page (admin only).
    'links' => [
        'ssh' => env('MEDIA_LINK_SSH'),
        'transmission' => env('MEDIA_LINK_TRANSMISSION'),
    ],

];
