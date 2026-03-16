<?php

namespace FloCMS\Uploader;

use FloCMS\Uploader\Exceptions\UploadException;
use FloCMS\Uploader\Naming\RandomFileNameGenerator;
use FloCMS\Uploader\Storage\StorageManager;
use FloCMS\Uploader\Validation\FileValidator;

class Uploader
{
    protected static array $globalConfig = [];

    protected array $config;
    protected string $diskName = 'public';
    protected string $directory = '';
    protected bool $useDatePath = false;
    protected bool $preserveOriginalName = false;
    protected ?string $fixedFilename = null;
    protected ?string $visibility = null;
    protected array $allowedExtensions = [];
    protected array $allowedMimeTypes = [];
    protected ?int $maxBytes = null;
    protected array $imageDimensions = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? static::$globalConfig;
        $this->diskName = (string) ($this->config['default_disk'] ?? 'public');
    }

    public static function configure(array $config): void
    {
        static::$globalConfig = $config;
    }

    public static function make(?array $config = null): static
    {
        return new static($config);
    }

    public static function disk(string $disk, ?array $config = null): static
    {
        return (new static($config))->onDisk($disk);
    }

    public static function image(?array $config = null): Image\ImageUploader
    {
        return new Image\ImageUploader($config ?? static::$globalConfig);
    }

    public function onDisk(string $disk): static
    {
        $this->diskName = $disk;
        return $this;
    }

    public function directory(string $directory): static
    {
        $this->directory = trim($directory, '/');
        return $this;
    }

    public function to(string $directory): static
    {
        return $this->directory($directory);
    }

    public function useDatePath(bool $enabled = true): static
    {
        $this->useDatePath = $enabled;
        return $this;
    }

    public function preserveOriginalName(bool $enabled = true): static
    {
        $this->preserveOriginalName = $enabled;
        return $this;
    }

    public function filename(string $filename): static
    {
        $filename = trim($filename);
        
        if ($filename === '') {
            throw new UploadException('Filename cannot be empty.');
        }

        $this->fixedFilename = $filename;
        return $this;
    }

    public function visibility(string $visibility): static
    {
        $visibility = strtolower(trim($visibility));

        if (!in_array($visibility, ['public', 'private'], true)) {
            throw new UploadException('Visibility must be either public or private.');
        }

        $this->visibility = $visibility;
        return $this;
    }

    public function allowExtensions(array $extensions): static
    {
        $this->allowedExtensions = array_values(array_unique(array_map(
        static fn ($ext) => strtolower(ltrim(trim((string) $ext), '.')),
        $extensions
        )));

        return $this;
    }

    public function allowMimeTypes(array $mimeTypes): static
    {
        $this->allowedMimeTypes = array_values(array_unique(array_map(
        static fn ($mime) => strtolower(trim((string) $mime)),
        $mimeTypes
        )));

        return $this;
    }

    public function maxBytes(int $bytes): static
    {
        $this->maxBytes = $bytes;
        return $this;
    }

    public function imageDimensions(array $dimensions): static
    {
        $this->imageDimensions = $dimensions;
        return $this;
    }

    public function upload(array $file, ?string $directory = null): UploadResult
    {
        if ($directory !== null) {
            $this->directory($directory);
        }

        $validator = new FileValidator();
        $metadata = $validator->validate($file, $this->validationRules());

        $storageManager = new StorageManager($this->config);
        $storage = $storageManager->disk($this->diskName);
        $targetDirectory = $this->buildDirectory();
        $filename = $this->buildFilename($metadata);
        $relativePath = $this->buildRelativePath($targetDirectory, $filename);

        $stored = $storage->put($relativePath, (string) $file['tmp_name'], [
            'visibility' => $this->resolvedVisibility($storageManager),
            'content_type' => $metadata['mime'],
        ]);

        return new UploadResult([
            'disk' => $this->diskName,
            'directory' => $targetDirectory,
            'path' => $stored['path'],
            'url' => $stored['url'],
            'filename' => $filename,
            'original_name' => $metadata['original_name'],
            'extension' => $metadata['extension'],
            'mime' => $metadata['mime'],
            'size' => $metadata['size'],
            'visibility' => $stored['visibility'],
            'absolute_path' => $stored['absolute_path'],
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
        ]);
    }

    public function uploadMany(array $files, ?string $directory = null): array
    {
        $normalizedFiles = $this->normalizeFiles($files);

        if ($normalizedFiles === []) {
            throw new UploadException('No files were uploaded.');
        }

        $results = [];

        foreach ($normalizedFiles as $file) {
            $results[] = $this->upload($file, $directory);
        }

        return $results;
    }

    protected function validationRules(bool $image = false): array
    {
        $rules = [];

        if ($this->maxBytes !== null) {
            $rules['max_bytes'] = $this->maxBytes;
        }

        if ($this->allowedExtensions !== []) {
            $rules['extensions'] = $this->allowedExtensions;
        }

        if ($this->allowedMimeTypes !== []) {
            $rules['mime_types'] = $this->allowedMimeTypes;
        }

        if ($image) {
            $rules['image'] = true;
        }

        if ($this->imageDimensions !== []) {
            $rules['image_dimensions'] = $this->imageDimensions;
        }

        return $rules;
    }

    protected function buildDirectory(): string
    {
        $parts = [];
        if ($this->directory !== '') {
            $parts[] = trim($this->directory, '/');
        }

        if ($this->useDatePath) {
            $parts[] = date('Y');
            $parts[] = date('m');
        }

        return trim(implode('/', $parts), '/');
    }

    protected function buildFilename(array $metadata, ?string $extensionOverride = null): string
    {
        $extension = strtolower(ltrim((string) ($extensionOverride ?? $metadata['extension'] ?? ''), '.'));

        if ($this->fixedFilename !== null && $this->fixedFilename !== '') {
            $base = pathinfo($this->fixedFilename, PATHINFO_FILENAME);
            $base = $this->sanitizeBaseFilename($base);
            $configuredExtension = strtolower((string) pathinfo($this->fixedFilename, PATHINFO_EXTENSION));
            if ($configuredExtension !== '') {
                $extension = $configuredExtension;
            }

            return $extension !== '' ? $base . '.' . $extension : $base;
        }

        if ($this->preserveOriginalName) {
            $base = $this->sanitizeBaseFilename((string) ($metadata['basename'] ?? 'file'));
            return $extension !== '' ? $base . '.' . $extension : $base;
        }

        $generator = new RandomFileNameGenerator();
        return $generator->generate($extension);
    }

    protected function sanitizeBaseFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $filename) ?: 'file';
        $filename = trim($filename, '-_');

        return $filename !== '' ? $filename : 'file';
    }

    protected function buildRelativePath(string $directory, string $filename): string
    {
        return trim($directory !== '' ? $directory . '/' . $filename : $filename, '/');
    }

    protected function resolvedVisibility(StorageManager $storageManager): string
    {
        return $this->visibility
            ?? (string) ($storageManager->diskConfig($this->diskName)['visibility'] ?? 'public');
    }

    protected function normalizeFiles(array $files): array
    {
        if (!isset($files['name'])) {
            throw new UploadException('Invalid multi-upload payload.');
        }

        if (!is_array($files['name'])) {
            if (($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return [];
            }

            return [$files];
        }

        $normalized = [];
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];

            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[] = $file;
        }

        return $normalized;
    }
}