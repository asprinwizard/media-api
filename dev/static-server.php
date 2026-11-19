<?php

/*
| Local development only: stands in for Apache's /media and /shared aliases.
|
|   php -S 127.0.0.1:8001 dev/static-server.php
|
| Vite proxies /media/* and /shared/* here. Reads paths from .env
| (MEDIA_SHARE_PATH, MEDIA_CAST_PATH). "?download" adds
| Content-Disposition: attachment, like the vhost rule.
*/

$env = [];
foreach (@file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], "\"'");
    }
}

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$roots = [
    '/media/' => $env['MEDIA_SHARE_PATH'] ?? '/home/share',
    '/shared/' => $env['MEDIA_CAST_PATH'] ?? '/home/public/cast',
];

foreach ($roots as $prefix => $root) {
    if (! str_starts_with($uri, $prefix) || str_contains($uri, '..')) {
        continue;
    }
    $file = rtrim($root, '/').'/'.substr($uri, strlen($prefix));
    if (! is_file($file)) {
        break;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($file));
    header('Accept-Ranges: none');
    if (isset($_GET['download'])) {
        header('Content-Disposition: attachment; filename="'.addslashes(basename($file)).'"');
    }
    readfile($file);

    return true;
}

http_response_code(404);
echo 'Not found';

return true;
