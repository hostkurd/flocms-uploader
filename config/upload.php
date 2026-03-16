<?php

return [
    'default_disk' => 'public',

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