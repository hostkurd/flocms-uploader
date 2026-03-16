<?php

require __DIR__ . '/../vendor/autoload.php';

use FloCMS\Uploader\Uploader;

Uploader::configure(require __DIR__ . '/../config/upload.php');

$result = Uploader::disk('public')
    ->directory('documents')
    ->useDatePath()
    ->allowExtensions(['pdf', 'docx', 'jpg', 'png'])
    ->maxBytes(10 * 1024 * 1024)
    ->upload($_FILES['attachment']);

print_r($result->toArray());