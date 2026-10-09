<?php

return [
    'default_disk' => 'public',
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'sh', 'bash', 'htaccess', 'user.ini',
    ],

    // Uploader::video(): mp4 and webm; set allow_mov to also accept .mov (video/quicktime)
    'video' => [
        'max_bytes' => 500 * 1024 * 1024,
        'allow_mov' => false,
        'poster_max_bytes' => 5 * 1024 * 1024,
    ],

    // ->chunked(): resumable uploads for files above upload_max_filesize.
    // Keep max_chunk_bytes below PHP's upload_max_filesize and post_max_size.
    'chunks' => [
        'directory' => ROOT . '/storage/uploads/.chunks',
        'chunk_size' => 5 * 1024 * 1024,
        'max_chunk_bytes' => 10 * 1024 * 1024,
        'max_bytes' => 2 * 1024 * 1024 * 1024,
        'expire' => 24 * 60 * 60,
    ],

    'disks' => [
        'public' => [
            'driver' => 'local',
            'root' => ROOT . '/public/uploads',
            'url' => '/uploads',
            'visibility' => 'public',
        ],

        'private' => [
            'driver' => 'local',
            'root' => ROOT . '/storage/uploads',
            'visibility' => 'private',
        ],

        's3' => [
            'driver' => 's3',
            'key' => getenv('AWS_ACCESS_KEY_ID') ?: '',
            'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
            'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            'bucket' => getenv('AWS_BUCKET') ?: '',
            'version' => 'latest',
            'visibility' => 'public',
            'url' => getenv('AWS_URL') ?: null,
            'prefix' => 'uploads',
        ],
    ],
];
