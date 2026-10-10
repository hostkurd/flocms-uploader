<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Chunked;

use FloCMS\Uploader\Exceptions\ChunkOffsetException;
use FloCMS\Uploader\Exceptions\StorageException;
use FloCMS\Uploader\Exceptions\UploadException;
use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\UploadResult;
use FloCMS\Uploader\Uploader;

/**
 * Chunked, resumable uploads for files larger than upload_max_filesize.
 *
 * Protocol (see examples/chunked-upload.php and .js):
 *  1. start($filename, $totalBytes)        -> ['upload_id' => ..., 'received_bytes' => 0, 'chunk_size' => ...]
 *  2. append($uploadId, $offset, $chunk)   -> repeat; $offset must equal the bytes already received
 *  3. status($uploadId)                    -> resume after a failure from 'received_bytes'
 *  4. complete($uploadId)                  -> UploadResult, validated and stored by the target uploader
 *
 * Chunks are appended to a file in a private temporary directory. When all
 * bytes have arrived, the file goes through the target uploader's normal
 * validation (extension, MIME, size) and storage. Upload ids are 128-bit
 * random secrets; pass an $owner (e.g. the user id) to bind an upload to the
 * user who started it.
 *
 * Config ('chunks' key of the upload config):
 *  - directory        temporary directory (default: system temp dir/flocms-chunks)
 *  - chunk_size       suggested chunk size in bytes (default 5 MB)
 *  - max_chunk_bytes  largest accepted chunk (default 10 MB)
 *  - max_bytes        largest total size when the target uploader has none (default 2 GB)
 *  - expire           seconds before unfinished uploads are removed (default 86400)
 */
class ChunkedUploader
{
    public const DEFAULT_CHUNK_SIZE = 5_242_880;
    public const DEFAULT_MAX_CHUNK_BYTES = 10_485_760;
    public const DEFAULT_MAX_BYTES = 2_147_483_648;
    public const DEFAULT_EXPIRE = 86_400;

    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const COPY_BUFFER = 1_048_576;

    private string $directory;
    private int $chunkSize;
    private int $maxChunkBytes;
    private int $maxBytes;
    private int $expire;

    public function __construct(
        private readonly Uploader $target,
        array $config = [],
        ?string $directory = null
    ) {
        $chunks = (array) ($config['chunks'] ?? []);

        $this->directory = rtrim(
            $directory ?? (string) ($chunks['directory'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flocms-chunks'),
            '/\\'
        );
        $this->maxChunkBytes = max(1, (int) ($chunks['max_chunk_bytes'] ?? self::DEFAULT_MAX_CHUNK_BYTES));
        $this->chunkSize = min($this->maxChunkBytes, max(1, (int) ($chunks['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE)));
        $this->maxBytes = $target->maxUploadBytes() ?? max(1, (int) ($chunks['max_bytes'] ?? self::DEFAULT_MAX_BYTES));
        $this->expire = max(60, (int) ($chunks['expire'] ?? self::DEFAULT_EXPIRE));
    }

    public function maxChunkBytes(int $bytes): static
    {
        if ($bytes < 1) {
            throw new UploadException('Maximum chunk size must be positive.');
        }

        $this->maxChunkBytes = $bytes;
        $this->chunkSize = min($this->chunkSize, $bytes);
        return $this;
    }

    public function chunkSize(int $bytes): static
    {
        if ($bytes < 1) {
            throw new UploadException('Chunk size must be positive.');
        }

        $this->chunkSize = min($bytes, $this->maxChunkBytes);
        return $this;
    }

    public function expireAfter(int $seconds): static
    {
        $this->expire = max(1, $seconds);
        return $this;
    }

    /**
     * Begin an upload. The name and declared size are checked against the
     * target uploader's rules right away, so a forbidden file is rejected
     * before any data is sent.
     *
     * @return array{upload_id: string, filename: string, total_bytes: int, received_bytes: int, complete: bool, chunk_size: int, max_chunk_bytes: int}
     */
    public function start(string $filename, int $totalBytes, ?string $owner = null): array
    {
        $filename = self::cleanFilename($filename);

        if ($totalBytes > $this->maxBytes) {
            throw new ValidationException(sprintf(
                'Uploaded file exceeds the maximum allowed size of %d bytes.',
                $this->maxBytes
            ));
        }

        $this->target->preflight($filename, $totalBytes);
        $this->ensureDirectory();
        $this->purgeExpired();

        $id = bin2hex(random_bytes(16));
        $meta = [
            'filename' => $filename,
            'total_bytes' => $totalBytes,
            'owner' => $owner === null ? null : hash('sha256', $owner),
            'created_at' => time(),
        ];

        if (
            file_put_contents($this->metaPath($id), json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || file_put_contents($this->partPath($id), '') === false
        ) {
            throw new StorageException('Unable to create the chunk upload.');
        }

        return $this->describe($id, $meta, 0);
    }

    /**
     * @return array{upload_id: string, filename: string, total_bytes: int, received_bytes: int, complete: bool, chunk_size: int, max_chunk_bytes: int}
     */
    public function status(string $uploadId, ?string $owner = null): array
    {
        $meta = $this->load($uploadId, $owner);
        clearstatcache(true, $this->partPath($uploadId));

        return $this->describe($uploadId, $meta, (int) filesize($this->partPath($uploadId)));
    }

    /**
     * Append one chunk at $offset (the number of bytes received so far).
     *
     * @param array|resource $chunk an entry of $_FILES, or a readable stream (e.g. fopen('php://input', 'rb'));
     *                              a stream you pass in is read but not closed
     * @throws ChunkOffsetException when $offset is not where the upload stands; resume from expectedOffset()
     */
    public function append(string $uploadId, int $offset, mixed $chunk, ?string $owner = null): array
    {
        $meta = $this->load($uploadId, $owner);
        $ownsSource = !is_resource($chunk);
        $source = $this->openChunk($chunk);
        $part = fopen($this->partPath($uploadId), 'r+b');

        if ($part === false) {
            if ($ownsSource) {
                fclose($source);
            }
            throw new StorageException('Unable to open the chunk upload.');
        }

        try {
            if (!flock($part, LOCK_EX)) {
                throw new StorageException('Unable to lock the chunk upload.');
            }

            $received = (int) fstat($part)['size'];
            if ($offset !== $received) {
                throw new ChunkOffsetException($received, $offset);
            }

            $total = (int) $meta['total_bytes'];
            fseek($part, 0, SEEK_END);
            $written = 0;

            while (!feof($source)) {
                $buffer = fread($source, self::COPY_BUFFER);
                if ($buffer === false) {
                    break;
                }
                if ($buffer === '') {
                    continue;
                }

                $written += strlen($buffer);

                if ($written > $this->maxChunkBytes || $received + $written > $total) {
                    ftruncate($part, $received);
                    throw new ValidationException($written > $this->maxChunkBytes
                        ? sprintf('Chunk exceeds the maximum chunk size of %d bytes.', $this->maxChunkBytes)
                        : 'Chunk goes past the declared file size.');
                }

                if (fwrite($part, $buffer) !== strlen($buffer)) {
                    ftruncate($part, $received);
                    throw new StorageException('Unable to write the chunk.');
                }
            }

            if ($written === 0) {
                throw new ValidationException('Chunk is empty.');
            }

            fflush($part);
            @touch($this->metaPath($uploadId));

            return $this->describe($uploadId, $meta, $received + $written);
        } finally {
            flock($part, LOCK_UN);
            fclose($part);
            if ($ownsSource) {
                fclose($source);
            }
        }
    }

    /**
     * Validate and store the finished file with the target uploader.
     *
     * When validation fails the upload is discarded; on storage errors it is
     * kept so complete() can be retried.
     */
    public function complete(string $uploadId, ?string $owner = null, ?string $directory = null): UploadResult
    {
        $status = $this->status($uploadId, $owner);

        if (!$status['complete']) {
            throw new ValidationException(sprintf(
                'Upload is incomplete: %d of %d bytes received.',
                $status['received_bytes'],
                $status['total_bytes']
            ));
        }

        $file = [
            'name' => $status['filename'],
            'type' => '',
            'tmp_name' => $this->partPath($uploadId),
            'error' => UPLOAD_ERR_OK,
            'size' => $status['total_bytes'],
        ];

        try {
            $result = $this->target->uploadAssembled($file, $directory);
        } catch (ValidationException $e) {
            $this->abort($uploadId, $owner);
            throw $e;
        }

        @unlink($this->partPath($uploadId));
        @unlink($this->metaPath($uploadId));

        return $result;
    }

    public function abort(string $uploadId, ?string $owner = null): void
    {
        $this->load($uploadId, $owner);

        @unlink($this->partPath($uploadId));
        @unlink($this->metaPath($uploadId));
    }

    /**
     * Remove uploads that have not received data for the expiry time.
     *
     * @return int number of uploads removed
     */
    public function purgeExpired(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metaFile) {
            $id = basename($metaFile, '.json');
            if (!preg_match(self::ID_PATTERN, $id)) {
                continue;
            }

            $part = $this->partPath($id);
            $lastActivity = max((int) @filemtime($metaFile), (int) @filemtime($part));

            if ($now - $lastActivity >= $this->expire) {
                @unlink($part);
                @unlink($metaFile);
                $removed++;
            }
        }

        return $removed;
    }

    private function describe(string $id, array $meta, int $received): array
    {
        $total = (int) $meta['total_bytes'];

        return [
            'upload_id' => $id,
            'filename' => (string) $meta['filename'],
            'total_bytes' => $total,
            'received_bytes' => $received,
            'complete' => $received === $total,
            'chunk_size' => $this->chunkSize,
            'max_chunk_bytes' => $this->maxChunkBytes,
        ];
    }

    private function load(string $uploadId, ?string $owner): array
    {
        $unknown = new ValidationException('Unknown or expired upload.');

        if (!preg_match(self::ID_PATTERN, $uploadId) || !is_file($this->metaPath($uploadId)) || !is_file($this->partPath($uploadId))) {
            throw $unknown;
        }

        $meta = json_decode((string) file_get_contents($this->metaPath($uploadId)), true);
        if (!is_array($meta) || !isset($meta['filename'], $meta['total_bytes'])) {
            throw $unknown;
        }

        // Same error as an unknown id, so ids of other users cannot be probed
        if (($meta['owner'] ?? null) !== null && ($owner === null || !hash_equals((string) $meta['owner'], hash('sha256', $owner)))) {
            throw $unknown;
        }

        return $meta;
    }

    /**
     * @return resource
     */
    private function openChunk(mixed $chunk)
    {
        if (is_resource($chunk)) {
            return $chunk;
        }

        if (!is_array($chunk)) {
            throw new ValidationException('Chunk must be an uploaded file or a stream.');
        }

        $error = (int) ($chunk['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException($error === UPLOAD_ERR_INI_SIZE
                ? 'Chunk exceeds upload_max_filesize; use a smaller chunk size.'
                : 'Chunk was not uploaded.');
        }

        $path = (string) ($chunk['tmp_name'] ?? '');
        $stream = $path !== '' && is_file($path) ? fopen($path, 'rb') : false;

        if ($stream === false) {
            throw new ValidationException('Uploaded chunk is missing.');
        }

        return $stream;
    }

    private static function cleanFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        $filename = trim($filename);

        if ($filename === '' || $filename === '.' || $filename === '..' || strlen($filename) > 255) {
            throw new ValidationException('Invalid file name.');
        }

        return $filename;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new StorageException('Unable to create the chunk directory.');
        }
    }

    private function metaPath(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function partPath(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.part';
    }
}
