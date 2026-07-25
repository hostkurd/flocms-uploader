<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Support;

use FloCMS\Uploader\Exceptions\StorageException;

final class SafePath
{
    public static function relative(string $path, bool $allowEmpty = false): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (
            str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path)
            || str_contains($path, "\0")
            || preg_match('/[\x01-\x1F\x7F]/', $path)
        ) {
            throw new StorageException('Storage paths must be safe relative paths.');
        }

        $path = preg_replace('#/+#', '/', $path);
        $path = is_string($path) ? trim($path, '/') : '';
        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new StorageException('Storage path cannot be empty.');
        }
        if (strlen($path) > 4096) {
            throw new StorageException('Storage path is too long.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_contains($segment, ':')
                || strlen($segment) > 255
                || preg_match('/[.\s]$/', $segment)
            ) {
                throw new StorageException('Storage path contains an unsafe segment.');
            }
        }

        return implode('/', $segments);
    }
}
