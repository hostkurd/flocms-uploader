<?php
/**
 * Endpoint for chunked, resumable uploads (use with chunked-upload.js).
 *
 * POST action=start     filename, size          -> {upload_id, received_bytes, chunk_size, ...}
 * POST action=append    upload_id, offset, chunk -> {received_bytes, complete, ...}
 * POST action=status    upload_id                -> {received_bytes, ...}  (resume)
 * POST action=complete  upload_id                -> {url, path, mime, size}
 *
 * In FloCMS, put this in a controller action. Send the CSRF token with each
 * request (X-CSRF-TOKEN header) and pass the logged-in user's id as $owner.
 */

require __DIR__ . '/../vendor/autoload.php';

use FloCMS\Uploader\Exceptions\ChunkOffsetException;
use FloCMS\Uploader\Exceptions\UploadException;
use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\Uploader;

Uploader::configure(require __DIR__ . '/../config/upload.php');

header('Content-Type: application/json; charset=utf-8');

$owner = (string) ($_SESSION['user_id'] ?? '');   // bind uploads to the current user
$owner = $owner !== '' ? $owner : null;
$chunked = Uploader::video()->directory('videos')->useDatePath()->chunked();
$uploadId = (string) ($_POST['upload_id'] ?? '');

try {
    $response = match ($_POST['action'] ?? '') {
        'start' => $chunked->start((string) ($_POST['filename'] ?? ''), (int) ($_POST['size'] ?? 0), $owner),
        'append' => $chunked->append($uploadId, (int) ($_POST['offset'] ?? -1), $_FILES['chunk'] ?? [], $owner),
        'status' => $chunked->status($uploadId, $owner),
        'complete' => (function () use ($chunked, $uploadId, $owner): array {
            $result = $chunked->complete($uploadId, $owner);
            return ['url' => $result->url(), 'path' => $result->path(), 'mime' => $result->mime(), 'size' => $result->size()];
        })(),
        default => throw new ValidationException('Unknown action.'),
    };

    echo json_encode($response);
} catch (ChunkOffsetException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage(), 'received_bytes' => $e->expectedOffset()]);
} catch (ValidationException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (UploadException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed.']);
}
