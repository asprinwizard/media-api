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

    // Serve files via Apache mod_xsendfile (production). Off in local dev,
    // where `php artisan serve` streams the file itself instead.
    'xsendfile' => (bool) env('MEDIA_XSENDFILE', true),

    // Unauthenticated signed links (Chromecast) stay valid this long.
    'signed_url_ttl_hours' => (int) env('MEDIA_SIGNED_URL_TTL_HOURS', 24),

    // Logs shown to admins on the home page (files in paths.log_dir, .log).
    'dashboard_logs' => [
        'media01-tv' => 'TV',
        'media01-movies' => 'Movies',
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
