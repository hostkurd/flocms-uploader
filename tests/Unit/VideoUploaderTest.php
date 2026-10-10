<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Tests\Unit;

use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\Tests\Support\Fixtures;
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\Video\VideoUploader;
use PHPUnit\Framework\TestCase;

final class VideoUploaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Fixtures::tempDir('flo-video-');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function config(array $video = []): array
    {
        return [
            'default_disk' => 'public',
            'video' => $video,
            'disks' => ['public' => ['driver' => 'local', 'root' => $this->root, 'url' => '/uploads']],
        ];
    }

    public function testStoresMp4AndWebm(): void
    {
        $mp4 = Uploader::video($this->config())->upload(Fixtures::upload('clip.mp4', Fixtures::mp4()), 'videos');
        $webm = Uploader::video($this->config())->upload(Fixtures::upload('clip.webm', Fixtures::webm()), 'videos');

        self::assertSame('video/mp4', $mp4->mime());
        self::assertSame('mp4', $mp4->extension());
        self::assertFileExists($this->root . '/' . $mp4->path());
        self::assertStringStartsWith('/uploads/videos/', (string) $mp4->url());
        self::assertSame('video/webm', $webm->mime());
        self::assertNull($mp4->poster());
    }

    public function testMovIsRejectedByDefault(): void
    {
        $this->expectException(ValidationException::class);
        Uploader::video($this->config())->upload(Fixtures::upload('phone.mov', Fixtures::mov()));
    }

    public function testMovCanBeEnabledInConfigOrPerUploader(): void
    {
        $fromConfig = Uploader::video($this->config(['allow_mov' => true]))
            ->upload(Fixtures::upload('phone.mov', Fixtures::mov()));
        self::assertSame('video/quicktime', $fromConfig->mime());

        $perUploader = Uploader::video($this->config())->allowMov()
            ->upload(Fixtures::upload('phone.mov', Fixtures::mov()));
        self::assertSame('mov', $perUploader->extension());
    }

    public function testContentMustMatchTheExtension(): void
    {
        try {
            Uploader::video($this->config())->upload(Fixtures::upload('clip.mp4', Fixtures::webm()));
            self::fail('A webm file named .mp4 must be rejected.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('does not match its .mp4 extension', $e->getMessage());
        }

        self::assertSame([], glob($this->root . '/*') ?: []);
    }

    public function testNonVideoContentIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        Uploader::video($this->config())->upload(Fixtures::upload('clip.mp4', '<?php echo "x";'));
    }

    public function testSizeLimitFromConfig(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('maximum allowed size of 100 bytes');
        Uploader::video($this->config(['max_bytes' => 100]))->upload(Fixtures::upload('clip.mp4', Fixtures::mp4(200)));
    }

    public function testDefaultSizeLimit(): void
    {
        self::assertSame(VideoUploader::DEFAULT_MAX_BYTES, Uploader::video($this->config())->maxUploadBytes());
    }

    public function testPosterIsStoredNextToTheVideo(): void
    {
        $result = Uploader::video($this->config())
            ->withPoster(Fixtures::upload('cover.png', Fixtures::png()))
            ->upload(Fixtures::upload('clip.mp4', Fixtures::mp4()), 'videos');

        $poster = $result->poster();
        self::assertNotNull($poster);
        self::assertSame('image/png', $poster->mime());
        self::assertSame(4, $poster->width());
        self::assertSame(
            pathinfo((string) $result->filename(), PATHINFO_FILENAME) . '-poster.png',
            $poster->filename()
        );
        self::assertSame('videos/' . $poster->filename(), $poster->path());
        self::assertFileExists($this->root . '/' . $poster->path());
    }

    public function testInvalidPosterStoresNothing(): void
    {
        try {
            Uploader::video($this->config())
                ->withPoster(Fixtures::upload('cover.png', 'not an image'))
                ->upload(Fixtures::upload('clip.mp4', Fixtures::mp4()), 'videos');
            self::fail('Expected the poster to be rejected.');
        } catch (ValidationException) {
        }

        self::assertSame([], glob($this->root . '/videos/*') ?: []);
    }

    public function testPosterMustBeAnImageType(): void
    {
        $this->expectException(ValidationException::class);
        Uploader::video($this->config())
            ->withPoster(Fixtures::upload('cover.mp4', Fixtures::mp4()))
            ->upload(Fixtures::upload('clip.mp4', Fixtures::mp4()));
    }

    public function testEmptyPosterFieldIsIgnored(): void
    {
        $result = Uploader::video($this->config())
            ->withPoster(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0])
            ->upload(Fixtures::upload('clip.webm', Fixtures::webm()));

        self::assertNull($result->poster());
    }

    public function testPosterSizeLimit(): void
    {
        $this->expectException(ValidationException::class);
        Uploader::video($this->config(['poster_max_bytes' => 10]))
            ->withPoster(Fixtures::upload('cover.png', Fixtures::png()))
            ->upload(Fixtures::upload('clip.mp4', Fixtures::mp4()));
    }
}
