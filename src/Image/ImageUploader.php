<?php

namespace FloCMS\Uploader\Image;

use FloCMS\Uploader\UploadResult;
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\Storage\StorageManager;
use FloCMS\Uploader\Validation\FileValidator;

class ImageUploader extends Uploader
{
    protected array $versions = [];
    protected bool $keepOriginal = true;
    protected bool $optimizeImages = true;
    protected int $quality = 85;
    protected ?string $imageDriver = null;

    public function __construct(?array $config = null)
    {
        parent::__construct($config);
        $this->allowExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $this->allowMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    public function versions(array $versions): static
    {
        $this->versions = $versions;
        return $this;
    }

    public function keepOriginal(bool $enabled = true): static
    {
        $this->keepOriginal = $enabled;
        return $this;
    }

    public function optimize(bool $enabled = true): static
    {
        $this->optimizeImages = $enabled;
        return $this;
    }

    public function quality(int $quality): static
    {
        $this->quality = max(0, min(100, $quality));
        return $this;
    }

    public function driver(string $driver): static
    {
        $this->imageDriver = $driver;
        return $this;
    }

    public function upload(array $file, ?string $directory = null): UploadResult
    {
        if ($directory !== null) {
            $this->directory($directory);
        }

        $validator = new FileValidator();
        $metadata = $validator->validate($file, $this->validationRules(true));

        $storageManager = new StorageManager($this->config);
        $storage = $storageManager->disk($this->diskName);
        $targetDirectory = $this->buildDirectory();
        $filename = $this->buildFilename($metadata);
        $relativeOriginal = $this->buildRelativePath($targetDirectory !== '' ? $targetDirectory . '/original' : 'original', $filename);

        $storedOriginal = null;
        if ($this->keepOriginal) {
            $storedOriginal = $storage->put($relativeOriginal, (string) $file['tmp_name'], [
                'visibility' => $this->resolvedVisibility($storageManager),
                'content_type' => $metadata['mime'],
            ]);
        }

        $processor = new ImageProcessor($this->imageDriver);
        $versions = [];
        $configuredVersions = $this->versions;
        if ($configuredVersions === []) {
            $configuredVersions = [
                'large' => ['resize' => [1600, 1600]],
                'medium' => ['resize' => [800, 800]],
                'thumb' => ['fit' => [300, 300]],
            ];
        }

        foreach ($configuredVersions as $versionName => $operations) {
            if ($versionName === 'original') {
                continue;
            }

            $format = isset($operations['format']) ? strtolower((string) $operations['format']) : $metadata['extension'];
            $filenameForVersion = pathinfo($filename, PATHINFO_FILENAME) . '.' . ltrim($format, '.');
            $relativePath = $this->buildRelativePath($targetDirectory !== '' ? $targetDirectory . '/' . $versionName : $versionName, $filenameForVersion);
            $tmpTarget = tempnam(sys_get_temp_dir(), 'flocms_img_');
            if ($tmpTarget === false) {
                throw new \RuntimeException('Unable to create temporary image file.');
            }
            unlink($tmpTarget);
            $tmpTarget .= '.' . $format;

            $processedMeta = $processor->process(
                (string) $file['tmp_name'],
                array_filter($operations, fn ($key) => in_array($key, ['resize', 'fit'], true), ARRAY_FILTER_USE_KEY),
                $tmpTarget,
                [
                    'format' => $format,
                    'quality' => $operations['quality'] ?? $this->quality,
                    'optimize' => $operations['optimize'] ?? $this->optimizeImages,
                ]
            );

            $storedVersion = $storage->put($relativePath, $tmpTarget, [
                'visibility' => $this->resolvedVisibility($storageManager),
                'content_type' => $processedMeta['mime'],
            ]);
            @unlink($tmpTarget);

            $versions[$versionName] = [
                'path' => $storedVersion['path'],
                'url' => $storedVersion['url'],
                'absolute_path' => $storedVersion['absolute_path'],
                'width' => $processedMeta['width'],
                'height' => $processedMeta['height'],
                'mime' => $processedMeta['mime'],
            ];
        }

        return new UploadResult([
            'disk' => $this->diskName,
            'directory' => $targetDirectory,
            'path' => $storedOriginal['path'] ?? ($versions[array_key_first($versions)]['path'] ?? null),
            'url' => $storedOriginal['url'] ?? ($versions[array_key_first($versions)]['url'] ?? null),
            'filename' => $filename,
            'original_name' => $metadata['original_name'],
            'extension' => $metadata['extension'],
            'mime' => $metadata['mime'],
            'size' => $metadata['size'],
            'visibility' => $storedOriginal['visibility'] ?? $this->resolvedVisibility($storageManager),
            'absolute_path' => $storedOriginal['absolute_path'] ?? null,
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'versions' => $versions,
            'original' => $storedOriginal,
        ]);
    }
}