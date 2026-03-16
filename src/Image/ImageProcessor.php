<?php

namespace FloCMS\Uploader\Image;

use FloCMS\Uploader\Exceptions\ImageProcessingException;
use FloCMS\Uploader\Image\Drivers\GdDriver;
use FloCMS\Uploader\Image\Drivers\ImagickDriver;

class ImageProcessor
{
    public function __construct(private readonly ?string $preferredDriver = null)
    {
    }

    public function process(string $sourceFile, array $operations, string $targetFile, array $options = []): array
    {
        $driver = $this->makeDriver();
        $driver->load($sourceFile);

        foreach ($operations as $operation => $arguments) {
            $arguments = (array) $arguments;
            switch ($operation) {
                case 'resize':
                    $driver->resize((int) ($arguments[0] ?? 0), (int) ($arguments[1] ?? 0), $arguments[2] ?? []);
                    break;
                case 'fit':
                    $driver->fit((int) ($arguments[0] ?? 0), (int) ($arguments[1] ?? 0), $arguments[2] ?? []);
                    break;
                case 'format':
                case 'quality':
                case 'optimize':
                    break;
                default:
                    throw new ImageProcessingException(sprintf('Unsupported image operation "%s".', $operation));
            }
        }

        $driver->save(
            $targetFile,
            $options['format'] ?? null,
            (int) ($options['quality'] ?? 85),
            (bool) ($options['optimize'] ?? true)
        );

        return [
            'width' => $driver->width(),
            'height' => $driver->height(),
            'mime' => $driver->mime(),
        ];
    }

    private function makeDriver(): ImageDriverInterface
    {
        $preferred = strtolower((string) $this->preferredDriver);

        if ($preferred === 'imagick') {
            return new ImagickDriver();
        }

        if ($preferred === 'gd') {
            return new GdDriver();
        }

        if (extension_loaded('imagick')) {
            return new ImagickDriver();
        }

        if (extension_loaded('gd')) {
            return new GdDriver();
        }

        throw new ImageProcessingException('No supported image driver was found. Install ext-imagick or ext-gd.');
    }
}