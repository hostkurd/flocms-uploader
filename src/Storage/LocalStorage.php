<?php

namespace FloCMS\Uploader\Storage;

use FloCMS\Uploader\Exceptions\StorageException;
use FloCMS\Uploader\Support\SafePath;

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

        $relativePath = SafePath::relative($path);
        $targetPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create upload directory: ' . $directory);
        }

        $resolvedRoot = realpath($root);
        $resolvedDirectory = realpath($directory);
        if (
            $resolvedRoot === false
            || $resolvedDirectory === false
            || !$this->isWithin($resolvedRoot, $resolvedDirectory)
            || is_link($targetPath)
        ) {
            throw new StorageException('Upload path escapes the configured storage root.');
        }

        if (is_uploaded_file($sourceFile)) {
            $moved = move_uploaded_file($sourceFile, $targetPath);
        } elseif (!empty($options['move'])) {
            // Assembled chunked uploads: rename instead of copying large files
            $moved = @rename($sourceFile, $targetPath);

            if (!$moved && copy($sourceFile, $targetPath)) {
                @unlink($sourceFile);
                $moved = true;
            }
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

        return rtrim((string) $base, '/') . '/' . SafePath::relative($path);
    }

    public function path(string $path): ?string
    {
        $root = $this->config['root'] ?? null;
        if (!$root) {
            return null;
        }

        $resolvedRoot = realpath((string) $root);
        if ($resolvedRoot === false) {
            return null;
        }

        $absolute = $resolvedRoot . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            SafePath::relative($path)
        );
        $existing = realpath($absolute);
        if ($existing !== false && !$this->isWithin($resolvedRoot, $existing)) {
            return null;
        }

        $parent = realpath(dirname($absolute));
        if ($existing === false && $parent !== false && !$this->isWithin($resolvedRoot, $parent)) {
            return null;
        }

        return $absolute;
    }

    private function isWithin(string $root, string $path): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }

        return $path === $root
            || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
