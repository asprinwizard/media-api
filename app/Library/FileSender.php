<?php

namespace App\Library;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a media file to the browser.
 *
 * Production: an empty response with X-Sendfile; Apache (mod_xsendfile)
 * streams the file, sets Content-Length and handles Range requests. PHP never
 * reads the file, which matters on 32-bit PHP where files over 2 GB can't be
 * opened or sized.
 *
 * Local dev (MEDIA_XSENDFILE=false): Symfony streams it itself.
 */
class FileSender
{
    public function send(string $absolutePath, string $mimeType, string $disposition): Response
    {
        $name = basename($absolutePath);
        $contentDisposition = HeaderUtils::makeDisposition($disposition, $name, $this->asciiFallback($name));

        if (! config('media.xsendfile')) {
            $response = new BinaryFileResponse($absolutePath, 200, ['Content-Type' => $mimeType], autoEtag: false, autoLastModified: true);
            $response->headers->set('Content-Disposition', $contentDisposition);

            return $response;
        }

        return new Response('', 200, [
            'X-Sendfile' => $absolutePath,
            'Content-Type' => $mimeType,
            'Content-Disposition' => $contentDisposition,
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'download';

        return str_replace(['%', '/', '\\', '"'], '_', $ascii);
    }
}
