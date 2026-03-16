<?php

namespace FloCMS\Uploader\Validation;

use FloCMS\Uploader\Exceptions\ValidationException;

class FileValidator
{
    public function validate(array $file, array $rules = []): array
    {
        $this->validateUploadArray($file);
        $this->validateUploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));
        $this->validateTempFile((string) $file['tmp_name']);

        $metadata = $this->extractMetadata((string) $file['tmp_name'], (string) ($file['name'] ?? 'file'));

        $size = (int) ($file['size'] ?? filesize((string) $file['tmp_name']) ?: 0);
        if (!empty($rules['max_bytes']) && $size > (int) $rules['max_bytes']) {
            throw new ValidationException(sprintf('Uploaded file exceeds the maximum allowed size of %d bytes.', (int) $rules['max_bytes']));
        }

        if (!empty($rules['extensions'])) {
            $allowed = array_map(fn ($ext) => strtolower(ltrim((string) $ext, '.')), (array) $rules['extensions']);
            if (!in_array($metadata['extension'], $allowed, true)) {
                throw new ValidationException('The uploaded file extension is not allowed.');
            }
        }

        if (!empty($rules['mime_types'])) {
            $allowedMimes = array_map('strtolower', (array) $rules['mime_types']);
            if (!in_array(strtolower($metadata['mime']), $allowedMimes, true)) {
                throw new ValidationException('The uploaded file MIME type is not allowed.');
            }
        }

        if (!empty($rules['image']) || !empty($rules['image_dimensions'])) {
            $imageInfo = @getimagesize((string) $file['tmp_name']);
            if ($imageInfo === false) {
                throw new ValidationException('The uploaded file is not a valid image.');
            }

            $metadata['width'] = (int) ($imageInfo[0] ?? 0);
            $metadata['height'] = (int) ($imageInfo[1] ?? 0);

            $dimensions = (array) ($rules['image_dimensions'] ?? []);
            $this->validateDimensions($metadata, $dimensions);
        }

        $metadata['size'] = $size;

        return $metadata;
    }

    private function validateUploadArray(array $file): void
    {
        foreach (['name', 'tmp_name', 'error'] as $key) {
            if (!array_key_exists($key, $file)) {
                throw new ValidationException(sprintf('Invalid upload payload. Missing key: %s', $key));
            }
        }
    }

    private function validateUploadError(int $error): void
    {
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        $map = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE form limit.',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The uploaded file could not be written to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];

        throw new ValidationException($map[$error] ?? 'Unknown file upload error.');
    }

    private function validateTempFile(string $tmpFile): void
    {
        if ($tmpFile === '' || !is_file($tmpFile)) {
            throw new ValidationException('Uploaded temporary file is missing.');
        }
    }

    private function extractMetadata(string $tmpFile, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmpFile) : 'application/octet-stream';
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        return [
            'original_name' => $originalName,
            'basename' => pathinfo($originalName, PATHINFO_FILENAME),
            'extension' => $extension,
            'mime' => $mime,
        ];
    }

    private function validateDimensions(array $metadata, array $dimensions): void
    {
        $width = (int) ($metadata['width'] ?? 0);
        $height = (int) ($metadata['height'] ?? 0);

        if (!empty($dimensions['min_width']) && $width < (int) $dimensions['min_width']) {
            throw new ValidationException('Image width is smaller than the minimum allowed width.');
        }

        if (!empty($dimensions['min_height']) && $height < (int) $dimensions['min_height']) {
            throw new ValidationException('Image height is smaller than the minimum allowed height.');
        }

        if (!empty($dimensions['max_width']) && $width > (int) $dimensions['max_width']) {
            throw new ValidationException('Image width exceeds the maximum allowed width.');
        }

        if (!empty($dimensions['max_height']) && $height > (int) $dimensions['max_height']) {
            throw new ValidationException('Image height exceeds the maximum allowed height.');
        }
    }
}