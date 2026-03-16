<?php

namespace FloCMS\Uploader\Image\Drivers;

use FloCMS\Uploader\Exceptions\ImageProcessingException;
use FloCMS\Uploader\Image\ImageDriverInterface;
use Imagick;
use ImagickException;

class ImagickDriver implements ImageDriverInterface
{
    private Imagick $image;

    public function load(string $path): void
    {
        if (!extension_loaded('imagick')) {
            throw new ImageProcessingException('Imagick extension is required for this image driver.');
        }

        try {
            $this->image = new Imagick($path);
            $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        } catch (ImagickException $e) {
            throw new ImageProcessingException('Failed to load image into Imagick.', 0, $e);
        }
    }

    public function width(): int
    {
        return $this->image->getImageWidth();
    }

    public function height(): int
    {
        return $this->image->getImageHeight();
    }

    public function mime(): string
    {
        return (string) $this->image->getImageMimeType();
    }

    public function resize(int $maxWidth, int $maxHeight, array $options = []): void
    {
        $keepAspect = $options['keep_aspect_ratio'] ?? true;
        $upsize = $options['upsize'] ?? false;

        if (!$keepAspect) {
            $this->image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1.0, !$upsize);
            return;
        }

        $this->image->thumbnailImage($maxWidth, $maxHeight, !$upsize, true);
    }

    public function fit(int $width, int $height, array $options = []): void
    {
        $this->image->cropThumbnailImage($width, $height);
    }

    public function save(string $path, ?string $format = null, int $quality = 85, bool $stripMetadata = true): void
    {
        try {
            if ($stripMetadata) {
                $this->image->stripImage();
            }

            if ($format !== null) {
                $this->image->setImageFormat(strtolower($format));
            }

            $this->image->setImageCompressionQuality(max(0, min(100, $quality)));

            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ImageProcessingException('Unable to create image output directory.');
            }

            $this->image->writeImage($path);
        } catch (ImagickException $e) {
            throw new ImageProcessingException('Failed to save processed image.', 0, $e);
        }
    }
}