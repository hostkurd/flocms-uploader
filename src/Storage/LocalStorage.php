<?php

namespace FloCMS\Uploader\Storage;

use FloCMS\Uploader\Exceptions\StorageException;

class LocalStorage implements StorageInterface
{
    public function __construct(private readonly array $config)
    {
    }

    public function put(string $path, string $sourceFile, array $options = []): array
    {
        $root = rtrim((string) ($this->config['root'] ?? ''), DIRECTORY_SEPARATOR);
        if ($root === '') {
            throw new StorageException('Local storage root is not configured.');
        }

        $relativePath = ltrim(str_replace('\\', '/', $path), '/');
        $targetPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create upload directory: ' . $directory);
        }

        if (is_uploaded_file($sourceFile)) {
            $moved = move_uploaded_file($sourceFile, $targetPath);
        } else {
            $moved = copy($sourceFile, $targetPath);
        }

        if (!$moved) {
            throw new StorageException('Failed to write file to local storage.');
        }

        @chmod($targetPath, 0644);

        return [
            'path' => $relativePath,
            'url' => $this->url($relativePath),
            'absolute_path' => $targetPath,
            'visibility' => $options['visibility'] ?? ($this->config['visibility'] ?? 'public'),
        ];
    }

    public function exists(string $path): bool
    {
        $absolute = $this->path($path);
        return $absolute !== null && is_file($absolute);
    }

    public function delete(string $path): bool
    {
        $absolute = $this->path($path);
        return $absolute !== null && is_file($absolute) ? unlink($absolute) : false;
    }

    public function url(string $path): ?string
    {
        $base = $this->config['url'] ?? null;
        if ($base === null || $base === '') {
            return null;
        }

        return rtrim((string) $base, '/') . '/' . ltrim($path, '/');
    }

    public function path(string $path): ?string
    {
        $root = $this->config['root'] ?? null;
        if (!$root) {
            return null;
        }

        return rtrim((string) $root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    }
}