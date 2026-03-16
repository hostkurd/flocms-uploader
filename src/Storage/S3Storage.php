<?php

namespace FloCMS\Uploader\Storage;

use Aws\S3\S3Client;
use FloCMS\Uploader\Exceptions\StorageException;

class S3Storage implements StorageInterface
{
    private S3Client $client;

    public function __construct(private readonly array $config)
    {
        if (!class_exists(S3Client::class)) {
            throw new StorageException('S3 driver requires aws/aws-sdk-php. Install it with Composer before using disk("s3").');
        }

        foreach (['key', 'secret', 'region', 'bucket'] as $key) {
            if (empty($this->config[$key])) {
                throw new StorageException(sprintf('S3 storage is missing required configuration key: %s', $key));
            }
        }

        $this->client = new S3Client([
            'version' => $this->config['version'] ?? 'latest',
            'region' => $this->config['region'],
            'credentials' => [
                'key' => $this->config['key'],
                'secret' => $this->config['secret'],
            ],
        ]);
    }

    public function put(string $path, string $sourceFile, array $options = []): array
    {
        $key = $this->prefix($path);

        $params = [
            'Bucket' => $this->config['bucket'],
            'Key' => $key,
            'SourceFile' => $sourceFile,
            'ACL' => (($options['visibility'] ?? $this->config['visibility'] ?? 'private') === 'public') ? 'public-read' : 'private',
        ];

        if (!empty($options['content_type'])) {
            $params['ContentType'] = $options['content_type'];
        }

        $this->client->putObject($params);

        return [
            'path' => ltrim($path, '/'),
            'url' => $this->url($path),
            'absolute_path' => null,
            'visibility' => $options['visibility'] ?? ($this->config['visibility'] ?? 'private'),
        ];
    }

    public function exists(string $path): bool
    {
        return $this->client->doesObjectExist($this->config['bucket'], $this->prefix($path));
    }

    public function delete(string $path): bool
    {
        $this->client->deleteObject([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefix($path),
        ]);

        return true;
    }

    public function url(string $path): ?string
    {
        if (!empty($this->config['url'])) {
            return rtrim((string) $this->config['url'], '/') . '/' . ltrim($this->prefix($path), '/');
        }

        return $this->client->getObjectUrl($this->config['bucket'], $this->prefix($path));
    }

    public function path(string $path): ?string
    {
        return null;
    }

    private function prefix(string $path): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $path = ltrim($path, '/');

        return $prefix !== '' ? $prefix . '/' . $path : $path;
    }
}