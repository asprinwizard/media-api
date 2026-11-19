<?php

namespace App\Acquire\Upload;

/**
 * A chunked-upload step that can't go ahead, with the HTTP status to send
 * and any extra fields the browser needs (e.g. which chunk to send next).
 */
class UploadRefused extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
