<?php

namespace FloCMS\Uploader\Storage;

interface StorageInterface
{
    public function put(string $path, string $sourceFile, array $options = []): array;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function url(string $path): ?string;

    public function path(string $path): ?string;
}