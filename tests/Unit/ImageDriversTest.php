<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Tests\Unit;

use FloCMS\Uploader\Image\ImageProcessor;
use FloCMS\Uploader\Tests\Support\Fixtures;
use FloCMS\Uploader\Uploader;
use PHPUnit\Framework\TestCase;

/** GD and Imagick must give the same sizes for the same operations. */
final class ImageDriversTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Fixtures::tempDir('flo-image-');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'gd' => ['gd'];
        yield 'imagick' => ['imagick'];
    }

    private function require(string $driver): void
    {
        if (!extension_loaded($driver)) {
            self::markTestSkipped($driver . ' is not installed.');
        }
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd is needed to create the source images.');
        }
    }

    private function source(int $width, int $height): string
    {
        $path = $this->root . '/source-' . $width . 'x' . $height . '.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 11, 110, 145));
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }

    /** @return array{int, int} */
    private function process(string $driver, int $width, int $height, array $operations): array
    {
        $out = $this->root . '/out-' . bin2hex(random_bytes(4)) . '.png';
        $meta = (new ImageProcessor($driver))->process($this->source($width, $height), $operations, $out, ['format' => 'png']);
        $size = getimagesize($out);
        self::assertSame([$meta['width'], $meta['height']], [$size[0], $size[1]], 'Reported size differs from the file');
        return [$size[0], $size[1]];
    }

    /** @dataProvider drivers */
    public function testResizeKeepsTheAspectRatioWithoutPadding(string $driver): void
    {
        $this->require($driver);
        self::assertSame([1920, 960], $this->process($driver, 2400, 1200, ['resize' => [1920, 1920]]));
        self::assertSame([960, 1920], $this->process($driver, 1200, 2400, ['resize' => [1920, 1920]]));
        self::assertSame([800, 600], $this->process($driver, 1600, 1200, ['resize' => [800, 800]]));
    }

    /** @dataProvider drivers */
    public function testResizeDoesNotEnlargeUnlessAsked(string $driver): void
    {
        $this->require($driver);
        self::assertSame([800, 400], $this->process($driver, 800, 400, ['resize' => [1920, 1920]]));
        self::assertSame([1920, 960], $this->process($driver, 800, 400, ['resize' => [1920, 1920, ['upsize' => true]]]));
    }

    /** @dataProvider drivers */
    public function testResizeWithoutAspectRatioUsesTheExactSize(string $driver): void
    {
        $this->require($driver);
        self::assertSame([300, 300], $this->process($driver, 800, 400, ['resize' => [300, 300, ['keep_aspect_ratio' => false]]]));
    }

    /** @dataProvider drivers */
    public function testFitCropsToTheExactSize(string $driver): void
    {
        $this->require($driver);
        self::assertSame([300, 300], $this->process($driver, 2400, 1200, ['fit' => [300, 300]]));
        self::assertSame([400, 225], $this->process($driver, 1000, 1000, ['fit' => [400, 225]]));
    }

    /** @dataProvider drivers */
    public function testImageUploaderVersionsHaveTheRightSizes(string $driver): void
    {
        $this->require($driver);
        $source = $this->source(2400, 1200);
        $file = ['name' => 'photo.png', 'type' => 'image/png', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => filesize($source)];
        $result = Uploader::image(['default_disk' => 'public', 'disks' => ['public' => ['driver' => 'local', 'root' => $this->root . '/public', 'url' => '/uploads', 'visibility' => 'public']]])
            ->driver($driver)->keepOriginal(false)->versions(['web' => ['resize' => [1920, 1920]], 'thumb' => ['fit' => [300, 200]]])
            ->upload($file);
        self::assertSame([1920, 960], [$result->version('web')['width'], $result->version('web')['height']]);
        self::assertSame([300, 200], [$result->version('thumb')['width'], $result->version('thumb')['height']]);
        self::assertSame([1920, 960], array_slice((array) getimagesize($result->version('web')['absolute_path']), 0, 2));
    }
}
