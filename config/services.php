<?php

return [

    // Convert (admin): CloudConvert pulls the file from media02 over SFTP,
    // converts it, and pushes the result back over SFTP.
    'cloudconvert' => [
        'key' => env('CLOUDCONVERT_API_KEY'),
    ],

    // The SFTP login CloudConvert uses to reach media02 (not used locally).
    'sftp' => [
        'host' => env('SFTP_HOST', 'sftp.aspdendev.com'),
        'port' => (int) env('SFTP_PORT', 1979),
        'username' => env('SFTP_USER'),
        'password' => env('SFTP_PASSWORD'),
    ],

];
