<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Tests\Unit;

use FloCMS\Uploader\Exceptions\StorageException;
use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\Storage\LocalStorage;
use FloCMS\Uploader\Support\SafePath;
use FloCMS\Uploader\Validation\FileValidator;
use PHPUnit\Framework\TestCase;

final class UploaderSecurityTest extends TestCase
{
    public function testTraversalPathIsRejected(): void
    {
        $this->expectException(StorageException::class);
        SafePath::relative('avatars/../../outside.php');
    }

    public function testFileSizeComesFromTemporaryFile(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'flo-upload-test-');
        self::assertIsString($temporary);
        file_put_contents($temporary, 'actual content');

        try {
            $this->expectException(ValidationException::class);
            (new FileValidator())->validate([
                'name' => 'document.txt',
                'tmp_name' => $temporary,
                'error' => UPLOAD_ERR_OK,
                'size' => 0,
            ], ['max_bytes' => 1]);
        } finally {
            @unlink($temporary);
        }
    }

    public function testLocalStorageKeepsWritesInsideConfiguredRoot(): void
    {
        $root = sys_get_temp_dir() . '/flo-upload-root-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $source = tempnam(sys_get_temp_dir(), 'flo-upload-source-');
        self::assertIsString($source);
        file_put_contents($source, 'safe');
        $storage = new LocalStorage(['root' => $root, 'url' => '/uploads']);

        try {
            $result = $storage->put('documents/file.txt', $source);
            self::assertSame('documents/file.txt', $result['path']);
            self::assertFileExists($root . '/documents/file.txt');
        } finally {
            @unlink($root . '/documents/file.txt');
            @rmdir($root . '/documents');
            @rmdir($root);
            @unlink($source);
        }
    }
}
