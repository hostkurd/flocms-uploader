<?php

namespace FloCMS\Uploader\Storage;

use FloCMS\Uploader\Exceptions\StorageException;

class StorageManager
{
    public function __construct(private readonly array $config)
    {
    }

    public function disk(?string $name = null): StorageInterface
    {
        $diskName = $name ?? ($this->config['default_disk'] ?? 'public');
        $diskConfig = $this->config['disks'][$diskName] ?? null;

        if (!is_array($diskConfig)) {
            throw new StorageException(sprintf('Upload disk "%s" is not configured.', $diskName));
        }

        $driver = strtolower((string) ($diskConfig['driver'] ?? 'local'));

        return match ($driver) {
            'local' => new LocalStorage($diskConfig),
            's3' => new S3Storage($diskConfig),
            default => throw new StorageException(sprintf('Unsupported upload driver "%s".', $driver)),
        };
    }

    public function diskConfig(?string $name = null): array
    {
        $diskName = $name ?? ($this->config['default_disk'] ?? 'public');
        $diskConfig = $this->config['disks'][$diskName] ?? null;

        if (!is_array($diskConfig)) {
            throw new StorageException(sprintf('Upload disk "%s" is not configured.', $diskName));
        }

        return $diskConfig;
    }
}