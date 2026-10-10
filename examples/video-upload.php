<?php

require __DIR__ . '/../vendor/autoload.php';

use FloCMS\Uploader\Uploader;

Uploader::configure(require __DIR__ . '/../config/upload.php');

// <input type="file" name="video"> and an optional <input type="file" name="poster">
$result = Uploader::video()
    ->directory('videos')
    ->useDatePath()
    ->withPoster($_FILES['poster'] ?? null)
    ->upload($_FILES['video']);

echo $result->url(), PHP_EOL;
echo $result->poster()?->url(), PHP_EOL;
