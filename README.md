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

## Security notes

- The package validates upload errors before moving files.
- It uses `finfo` to detect MIME type.
- It validates file extension and MIME type independently.
- It uses random filenames by default.
- It validates real images with `getimagesize()` before image processing.
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

## Notes

- `Uploader::disk('public')` is the clean entry point for regular files.
- `Uploader::image()->onDisk('public')` is the clean entry point for image processing.
- If you want a framework-level helper later, you can wrap this package with your own `upload()` or `uploader()` helper in FloCMS core.