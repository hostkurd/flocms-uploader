# Changelog

## 1.2.1 - 2026-10-16

### Fixed
- Imagick driver: `resize` padded every image to the full box (a 2400×1200
  photo resized to 1920×1920 became a 1920×1920 image with bands) and enlarged
  images smaller than the box. It now gives the same sizes as the GD driver:
  aspect ratio kept, no enlarging unless `upsize` is set, and the exact size
  when `keep_aspect_ratio` is false. `fit` was already correct.
- Tests for both image drivers with the same cases (skipped when an extension
  is not installed).

## 1.2.0 - 2026-10-10

### Added
- `Uploader::video()` / `FloCMS\Uploader\Video\VideoUploader`: mp4 and webm
  by default, the detected MIME type must match the extension, size limit
  from `video.max_bytes` (default 500 MB). mov (`video/quicktime`) is opt-in
  via `video.allow_mov` or `allowMov()`.
- Optional poster image for videos (`withPoster()`, jpg/png/webp, validated as
  an image before anything is stored, `video.poster_max_bytes`), returned by
  `UploadResult::poster()`.
- Chunked, resumable uploads: `$uploader->chunked()` /
  `FloCMS\Uploader\Chunked\ChunkedUploader` with `start()`, `append()`,
  `status()`, `complete()`, `abort()` and `purgeExpired()`. Offsets must
  match the received bytes (`ChunkOffsetException::expectedOffset()` for
  resuming), chunk and total sizes are limited, uploads can be bound to an
  owner, and the finished file is validated and stored by the target uploader.
  Configure with the `chunks` section.
- `Uploader::preflight()` rejects a file by name and declared size before it
  is uploaded; `Uploader::maxUploadBytes()`.
- Examples: `video-upload.php`, `chunked-upload.php`, `chunked-upload.js`.
- `.gitignore` for `vendor/`, `composer.lock` and the PHPUnit cache.

### Changed
- `LocalStorage::put()` accepts a `move` option: assembled chunked uploads are
  renamed into place instead of copied.

## 1.1.0 - 2026-07-25

- Reject traversal, absolute paths, unsafe path segments, and local-storage
  symlink escapes.
- Calculate upload size from the temporary file instead of client metadata.
- Block common executable/script extensions by default.
- Prevent fixed filenames from changing the validated extension.
- Restrict generated image formats and reliably clean temporary files.
- Add focused security tests.
