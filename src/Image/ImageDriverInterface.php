<?php

namespace FloCMS\Uploader\Image;

interface ImageDriverInterface
{
    public function load(string $path): void;

    public function width(): int;

    public function height(): int;

    public function mime(): string;

    public function resize(int $maxWidth, int $maxHeight, array $options = []): void;

    public function fit(int $width, int $height, array $options = []): void;

    public function save(string $path, ?string $format = null, int $quality = 85, bool $stripMetadata = true): void;
}