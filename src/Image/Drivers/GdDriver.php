<?php

namespace FloCMS\Uploader\Image\Drivers;

use FloCMS\Uploader\Exceptions\ImageProcessingException;
use FloCMS\Uploader\Image\ImageDriverInterface;

class GdDriver implements ImageDriverInterface
{
    private $image = null;
    private int $width = 0;
    private int $height = 0;
    private string $mime = '';

    public function __destruct()
    {
        if (is_resource($this->image) || $this->image instanceof \GdImage) {
            imagedestroy($this->image);
        }
    }

    public function load(string $path): void
    {
        if (!extension_loaded('gd')) {
            throw new ImageProcessingException('GD extension is required for image processing.');
        }

        $info = @getimagesize($path);
        if ($info === false) {
            throw new ImageProcessingException('Unable to read image dimensions.');
        }

        $this->width = (int) $info[0];
        $this->height = (int) $info[1];
        $this->mime = (string) ($info['mime'] ?? '');

        $this->image = match ($this->mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : throw new ImageProcessingException('WEBP is not supported by your GD installation.'),
            default => throw new ImageProcessingException('Unsupported image format for GD: ' . $this->mime),
        };

        if (!$this->image) {
            throw new ImageProcessingException('Failed to load image into GD.');
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function resize(int $maxWidth, int $maxHeight, array $options = []): void
    {
        $keepAspect = $options['keep_aspect_ratio'] ?? true;
        $upsize = $options['upsize'] ?? false;

        if (!$keepAspect) {
            $newWidth = $maxWidth;
            $newHeight = $maxHeight;
        } else {
            $ratio = min($maxWidth / max(1, $this->width), $maxHeight / max(1, $this->height));
            if (!$upsize) {
                $ratio = min(1, $ratio);
            }
            $newWidth = max(1, (int) round($this->width * $ratio));
            $newHeight = max(1, (int) round($this->height * $ratio));
        }

        $this->resample($newWidth, $newHeight, 0, 0, $this->width, $this->height);
    }

    public function fit(int $width, int $height, array $options = []): void
    {
        $srcRatio = $this->width / max(1, $this->height);
        $targetRatio = $width / max(1, $height);

        if ($srcRatio > $targetRatio) {
            $cropHeight = $this->height;
            $cropWidth = (int) round($this->height * $targetRatio);
            $srcX = (int) floor(($this->width - $cropWidth) / 2);
            $srcY = 0;
        } else {
            $cropWidth = $this->width;
            $cropHeight = (int) round($this->width / $targetRatio);
            $srcX = 0;
            $srcY = (int) floor(($this->height - $cropHeight) / 2);
        }

        $this->resample($width, $height, $srcX, $srcY, $cropWidth, $cropHeight);
    }

    public function save(string $path, ?string $format = null, int $quality = 85, bool $stripMetadata = true): void
    {
        $format = strtolower($format ?: $this->extensionFromMime($this->mime));
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ImageProcessingException('Unable to create image output directory.');
        }

        $result = match ($format) {
            'jpg', 'jpeg' => imagejpeg($this->image, $path, max(0, min(100, $quality))),
            'png' => imagepng($this->image, $path, 9),
            'gif' => imagegif($this->image, $path),
            'webp' => function_exists('imagewebp') ? imagewebp($this->image, $path, max(0, min(100, $quality))) : false,
            default => false,
        };

        if (!$result) {
            throw new ImageProcessingException('Failed to save processed image.');
        }
    }

    private function resample(int $dstWidth, int $dstHeight, int $srcX, int $srcY, int $srcWidth, int $srcHeight): void
    {
        $canvas = imagecreatetruecolor($dstWidth, $dstHeight);
        if (!$canvas) {
            throw new ImageProcessingException('Unable to allocate GD image canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $dstWidth, $dstHeight, $transparent);

        if (!imagecopyresampled($canvas, $this->image, 0, 0, $srcX, $srcY, $dstWidth, $dstHeight, $srcWidth, $srcHeight)) {
            throw new ImageProcessingException('Failed to resample the image.');
        }

        if (is_resource($this->image) || $this->image instanceof \GdImage) {
            imagedestroy($this->image);
        }

        $this->image = $canvas;
        $this->width = $dstWidth;
        $this->height = $dstHeight;
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}