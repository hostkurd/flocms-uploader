<?php

require __DIR__ . '/../vendor/autoload.php';

use FloCMS\Uploader\Uploader;

Uploader::configure(require __DIR__ . '/../config/upload.php');

$result = Uploader::image()
    ->onDisk('public')
    ->directory('posts')
    ->useDatePath()
    ->maxBytes(5 * 1024 * 1024)
    ->imageDimensions([
        'min_width' => 300,
        'min_height' => 300,
    ])
    ->versions([
        'large' => ['resize' => [1600, 1600]],
        'medium' => ['resize' => [800, 800]],
        'thumb' => ['fit' => [300, 300], 'format' => 'webp', 'quality' => 82],
    ])
    ->upload($_FILES['image']);

print_r($result->toArray());