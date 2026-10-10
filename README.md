# FloCMS Uploader

`hostkurd/flocms-uploader` is a production-ready upload package for FloCMS. It supports:

- local public uploads
- local private uploads
- AWS S3 uploads
- secure MIME and extension validation
- random filenames by default
- optional original-name preservation
- date-based folders
- single and multi-file uploads
- image resizing, fitting, optimization, and version generation
- GD or Imagick image drivers

## Installation

```bash
composer require hostkurd/flocms-uploader
```

For AWS S3 support:

```bash
composer require aws/aws-sdk-php
```

## Recommended FloCMS structure

- `public/uploads` for publicly accessible assets like avatars, post images, and product photos.
- `storage/uploads` for protected files like invoices, reports, and private user documents.

## Configure the package

Create `config/upload.php` in your FloCMS app and copy the example from this package.

```php
use FloCMS\Uploader\Uploader;

Uploader::configure(require ROOT . '/config/upload.php');
```

A good place to do that is inside your bootstrap sequence, right after your framework loads config.

## Example config

```php
return [
    'default_disk' => 'public',
    'disks' => [
        'public' => [
            'driver' => 'local',
            'root' => ROOT . '/public/uploads',
            'url' => '/uploads',
            'visibility' => 'public',
        ],
        'private' => [
            'driver' => 'local',
            'root' => ROOT . '/storage/uploads',
            'visibility' => 'private',
        ],
        's3' => [
            'driver' => 's3',
            'key' => getenv('AWS_ACCESS_KEY_ID') ?: '',
            'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
            'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
            'bucket' => getenv('AWS_BUCKET') ?: '',
            'url' => getenv('AWS_URL') ?: null,
            'prefix' => 'uploads',
            'visibility' => 'public',
        ],
    ],
];
```

## Basic file upload

```php
use FloCMS\Uploader\Uploader;

Uploader::configure(require ROOT . '/config/upload.php');

$result = Uploader::disk('public')
    ->directory('documents')
    ->useDatePath()
    ->allowExtensions(['pdf', 'docx', 'jpg', 'jpeg', 'png'])
    ->maxBytes(10 * 1024 * 1024)
    ->upload($_FILES['attachment']);

$fileData = $result->toArray();
```

### Directory selection

You can choose the target directory per upload:

```php
Uploader::disk('public')->directory('avatars')->upload($_FILES['avatar']);
Uploader::disk('private')->directory('invoices')->upload($_FILES['invoice']);
```

### Multiple uploads

```php
$results = Uploader::disk('public')
    ->directory('gallery')
    ->uploadMany($_FILES['images']);
```

## Image uploads with versions

```php
$result = Uploader::image()
    ->onDisk('public')
    ->directory('posts')
    ->useDatePath()
    ->maxBytes(5 * 1024 * 1024)
    ->versions([
        'large' => ['resize' => [1600, 1600]],
        'medium' => ['resize' => [800, 800]],
        'thumb' => ['fit' => [300, 300], 'format' => 'webp', 'quality' => 82],
    ])
    ->upload($_FILES['image']);

$thumbUrl = $result->versionUrl('thumb');
```

The original image is kept by default and stored under:

```text
posts/2026/03/original/abc123.jpg
```

Generated versions are stored like:

```text
posts/2026/03/large/abc123.jpg
posts/2026/03/medium/abc123.jpg
posts/2026/03/thumb/abc123.webp
```

## AWS S3 example

```php
$result = Uploader::disk('s3')
    ->directory('products')
    ->useDatePath()
    ->allowExtensions(['jpg', 'jpeg', 'png', 'webp'])
    ->upload($_FILES['image']);
```

## Video uploads (1.2+)

```php
$result = Uploader::video()
    ->directory('videos')
    ->withPoster($_FILES['poster'] ?? null)   // optional jpg/png/webp
    ->upload($_FILES['video']);

$result->url();
$result->poster()?->url();   // stored as "<video name>-poster.<ext>"
```

- Accepts **mp4** (`video/mp4`) and **webm** (`video/webm`). The MIME type
  detected from the content must match the extension.
- **mov** (`video/quicktime`, e.g. from iPhones) is off by default. Enable it
  with `'video' => ['allow_mov' => true]` in the config or `->allowMov()`.
- Size limit: `video.max_bytes` (default 500 MB). Poster limit:
  `video.poster_max_bytes` (default 5 MB).
- The poster is validated before anything is stored; if storing it fails, the
  video is removed again.

## Chunked, resumable uploads (1.2+)

Shared hosting often limits `upload_max_filesize` to a few MB. Chunked uploads
send a large file in pieces below that limit and can resume after a network
error. Any uploader can be chunked; the finished file goes through that
uploader's normal validation and storage.

```php
$chunked = Uploader::video()->directory('videos')->chunked();

$session = $chunked->start($filename, $totalBytes, $userId);  // checks name and size first
$status  = $chunked->append($session['upload_id'], $offset, $_FILES['chunk'], $userId);
$status  = $chunked->status($uploadId, $userId);              // resume: continue at received_bytes
$result  = $chunked->complete($uploadId, $userId);            // UploadResult
```

- `$offset` must equal the bytes already received; otherwise
  `ChunkOffsetException::expectedOffset()` tells the client where to resume.
- A chunk can be a `$_FILES` entry or a stream such as `fopen('php://input', 'rb')`.
- Upload ids are random 128-bit values. Pass the logged-in user's id as the
  owner so only that user can continue or complete the upload.
- Chunks are stored in `chunks.directory` (keep it outside the web root) and
  moved, not copied, into local storage when complete. Uploads without
  activity for `chunks.expire` seconds are removed by `purgeExpired()`, which
  `start()` also runs.
- Keep `chunks.max_chunk_bytes` below PHP's `upload_max_filesize` and
  `post_max_size`.

See `examples/chunked-upload.php` (endpoint) and `examples/chunked-upload.js`
(browser client with retry and resume).

## Security notes

- The package validates upload errors before moving files.
- It uses `finfo` to detect MIME type.
- It validates file extension and MIME type independently.
- It uses random filenames by default.
- It validates real images with `getimagesize()` before image processing.
- It rejects traversal, absolute paths, unsafe path segments, and symlink escapes
  from local storage roots.
- It calculates upload size from the temporary file rather than trusting the
  browser-provided size.
- Common executable/script extensions are blocked by default. Configure
  `blocked_extensions` only if a private-storage use case requires a different
  policy.
- A fixed filename cannot replace the extension that was validated.
- It does not support SVG sanitization. Do not allow SVG uploads until you add a sanitizer.

## Public API overview

### `FloCMS\Uploader\Uploader`

- `configure(array $config): void`
- `disk(string $disk): Uploader`
- `make(?array $config = null): Uploader`
- `onDisk(string $disk): self`
- `directory(string $directory): self`
- `to(string $directory): self`
- `useDatePath(bool $enabled = true): self`
- `preserveOriginalName(bool $enabled = true): self`
- `filename(string $filename): self`
- `visibility(string $visibility): self`
- `allowExtensions(array $extensions): self`
- `allowMimeTypes(array $mimes): self`
- `maxBytes(int $bytes): self`
- `imageDimensions(array $dimensions): self`
- `upload(array $file, ?string $directory = null): UploadResult`
- `uploadMany(array $files, ?string $directory = null): array`
- `video(?array $config = null): VideoUploader` (1.2)
- `chunked(?string $temporaryDirectory = null): ChunkedUploader` (1.2)
- `preflight(string $filename, int $bytes): void` (1.2)
- `maxUploadBytes(): ?int` (1.2)

### `FloCMS\Uploader\Video\VideoUploader` (1.2)

- `allowMov(bool $enabled = true): self`
- `withPoster(?array $file): self`
- `posterMaxBytes(int $bytes): self`

### `FloCMS\Uploader\Chunked\ChunkedUploader` (1.2)

- `start(string $filename, int $totalBytes, ?string $owner = null): array`
- `append(string $uploadId, int $offset, array|resource $chunk, ?string $owner = null): array`
- `status(string $uploadId, ?string $owner = null): array`
- `complete(string $uploadId, ?string $owner = null, ?string $directory = null): UploadResult`
- `abort(string $uploadId, ?string $owner = null): void`
- `purgeExpired(): int`
- `chunkSize(int $bytes)`, `maxChunkBytes(int $bytes)`, `expireAfter(int $seconds)`

### `FloCMS\Uploader\Image\ImageUploader`

- `versions(array $versions): self`
- `keepOriginal(bool $enabled = true): self`
- `optimize(bool $enabled = true): self`
- `quality(int $quality): self`
- `driver(string $driver): self`

## FloCMS integration suggestion

Inside your bootstrap after config is loaded:

```php
use FloCMS\Uploader\Uploader;

if (is_file(ROOT . '/config/upload.php')) {
    Uploader::configure(require ROOT . '/config/upload.php');
}
```

Then your controllers can use the package directly.

## Upgrading from 1.1

1.2 is non-breaking. To use the new features, add the `video` and `chunks`
sections from `config/upload.php` to your config (all keys are optional) and
create the chunk directory outside the web root (e.g. `storage/uploads/.chunks`).
`UploadResult` has a new `poster()` method; local storage accepts a `move`
option used internally for assembled chunked uploads.

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

## Notes

- `Uploader::disk('public')` is the clean entry point for regular files.
- `Uploader::image()->onDisk('public')` is the clean entry point for image processing.
- If you want a framework-level helper later, you can wrap this package with your own `upload()` or `uploader()` helper in FloCMS core.
