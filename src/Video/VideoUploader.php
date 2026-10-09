<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Video;

use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\UploadResult;
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\Validation\FileValidator;
use Throwable;

/**
 * Video uploads with an optional poster image.
 *
 * Accepts mp4 and webm by default; mov (video/quicktime) only when enabled
 * with 'video' => ['allow_mov' => true] or allowMov(). The detected MIME type
 * must match the file extension. Large videos are usually sent in chunks:
 * Uploader::video()->chunked().
 *
 * Config ('video' key of the upload config):
 *  - max_bytes         maximum video size (default 500 MB)
 *  - allow_mov         also accept .mov / video/quicktime (default false)
 *  - poster_max_bytes  maximum poster image size (default 5 MB)
 */
class VideoUploader extends Uploader
{
    public const DEFAULT_MAX_BYTES = 524_288_000;
    public const DEFAULT_POSTER_MAX_BYTES = 5_242_880;

    /** MIME types accepted for each video extension. */
    public const MIME_TYPES = [
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
    ];

    private const POSTER_MIME_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    protected ?array $posterFile = null;
    protected int $posterMaxBytes;

    public function __construct(?array $config = null)
    {
        parent::__construct($config);

        $video = (array) ($this->config['video'] ?? []);
        $this->maxBytes((int) ($video['max_bytes'] ?? self::DEFAULT_MAX_BYTES));
        $this->posterMaxBytes = max(1, (int) ($video['poster_max_bytes'] ?? self::DEFAULT_POSTER_MAX_BYTES));
        $this->allowMov(!empty($video['allow_mov']));
    }

    /**
     * Accept .mov (video/quicktime) in addition to mp4 and webm.
     */
    public function allowMov(bool $enabled = true): static
    {
        $extensions = $enabled ? ['mp4', 'webm', 'mov'] : ['mp4', 'webm'];

        $this->allowExtensions($extensions);
        $this->allowMimeTypes(array_merge(...array_map(
            static fn (string $extension): array => self::MIME_TYPES[$extension],
            $extensions
        )));

        return $this;
    }

    /**
     * Store a poster image (jpg, png or webp) next to the video, named
     * "<video name>-poster.<ext>". Pass null or an empty upload to skip.
     */
    public function withPoster(?array $file): static
    {
        $this->posterFile = ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
            ? null
            : $file;

        return $this;
    }

    public function posterMaxBytes(int $bytes): static
    {
        if ($bytes < 1) {
            throw new ValidationException('Maximum poster size must be positive.');
        }

        $this->posterMaxBytes = $bytes;
        return $this;
    }

    public function upload(array $file, ?string $directory = null): UploadResult
    {
        if ($directory !== null) {
            $this->directory($directory);
        }

        $validator = new FileValidator();
        $metadata = $validator->validate($file, $this->validationRules());
        self::assertMimeMatchesExtension($metadata, self::MIME_TYPES, 'video');

        // Validate the poster before anything is stored
        $poster = $this->posterFile;
        $posterMetadata = null;
        if ($poster !== null) {
            $posterMetadata = $validator->validate($poster, [
                'blocked_extensions' => (array) ($this->validationRules()['blocked_extensions'] ?? []),
                'extensions' => array_keys(self::POSTER_MIME_TYPES),
                'mime_types' => array_values(array_unique(array_merge(...array_values(self::POSTER_MIME_TYPES)))),
                'image' => true,
                'max_bytes' => $this->posterMaxBytes,
            ]);
            self::assertMimeMatchesExtension($posterMetadata, self::POSTER_MIME_TYPES, 'poster image');
        }

        $video = $this->storeValidated($file, $metadata);

        if ($poster === null) {
            return $video;
        }

        try {
            $posterName = pathinfo((string) $video->filename(), PATHINFO_FILENAME)
                . '-poster.' . $posterMetadata['extension'];

            $wasMoving = $this->moveSource;
            $this->moveSource = false;
            try {
                $posterResult = $this->storeValidated($poster, $posterMetadata, $posterName);
            } finally {
                $this->moveSource = $wasMoving;
            }
        } catch (Throwable $e) {
            // Do not leave a video without the poster the caller asked for
            $this->deleteStored($video);
            throw $e;
        }

        return new UploadResult(array_merge($video->toArray(), ['poster' => $posterResult]));
    }

    /**
     * @param array<string, list<string>> $map extension => allowed MIME types
     */
    private static function assertMimeMatchesExtension(array $metadata, array $map, string $label): void
    {
        $extension = (string) ($metadata['extension'] ?? '');

        if (!isset($map[$extension])) {
            // Extensions added with allowExtensions() are checked by the generic rules only
            return;
        }

        if (!in_array(strtolower((string) $metadata['mime']), $map[$extension], true)) {
            throw new ValidationException(sprintf(
                'The %s content (%s) does not match its .%s extension.',
                $label,
                $metadata['mime'],
                $extension
            ));
        }
    }

    private function deleteStored(UploadResult $result): void
    {
        try {
            $storage = (new \FloCMS\Uploader\Storage\StorageManager($this->config))->disk($result->disk());
            $storage->delete((string) $result->path());
        } catch (Throwable) {
            // Best effort: the original error is more useful to the caller
        }
    }
}
