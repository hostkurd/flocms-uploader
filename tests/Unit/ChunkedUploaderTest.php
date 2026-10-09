<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Tests\Unit;

use FloCMS\Uploader\Chunked\ChunkedUploader;
use FloCMS\Uploader\Exceptions\ChunkOffsetException;
use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\Tests\Support\Fixtures;
use FloCMS\Uploader\Uploader;
use PHPUnit\Framework\TestCase;

final class ChunkedUploaderTest extends TestCase
{
    private string $root;
    private string $chunks;

    protected function setUp(): void
    {
        $this->root = Fixtures::tempDir('flo-chunk-root-');
        $this->chunks = Fixtures::tempDir('flo-chunk-tmp-');
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();
    }

    private function config(array $chunks = [], array $video = []): array
    {
        return [
            'default_disk' => 'public',
            'video' => $video,
            'chunks' => $chunks + ['directory' => $this->chunks],
            'disks' => ['public' => ['driver' => 'local', 'root' => $this->root, 'url' => '/uploads']],
        ];
    }

    /** @return resource */
    private static function stream(string $data)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    /** @return list<string> */
    private static function split(string $data, int $size): array
    {
        return str_split($data, $size);
    }

    public function testUploadsAVideoInChunks(): void
    {
        $video = Fixtures::mp4(5000);
        $chunked = Uploader::video($this->config())->chunked();

        $session = $chunked->start('holiday.mp4', strlen($video));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session['upload_id']);
        self::assertSame(0, $session['received_bytes']);

        $offset = 0;
        foreach (self::split($video, 1024) as $piece) {
            $status = $chunked->append($session['upload_id'], $offset, Fixtures::upload('blob', $piece));
            $offset = $status['received_bytes'];
        }

        self::assertTrue($status['complete']);
        $result = $chunked->complete($session['upload_id'], null, 'videos');

        self::assertSame('video/mp4', $result->mime());
        self::assertSame('holiday.mp4', $result->originalName());
        self::assertSame(strlen($video), $result->size());
        self::assertSame($video, file_get_contents($this->root . '/' . $result->path()));
        // Temporary files are gone (moved, not copied)
        self::assertSame([], glob($this->chunks . '/*') ?: []);
    }

    public function testAcceptsRawStreams(): void
    {
        $video = Fixtures::webm(3000);
        $chunked = Uploader::video($this->config())->chunked();
        $id = $chunked->start('clip.webm', strlen($video))['upload_id'];

        $stream = self::stream(substr($video, 0, 2000));
        $chunked->append($id, 0, $stream);
        self::assertIsResource($stream, 'a stream passed in is not closed');
        $chunked->append($id, 2000, self::stream(substr($video, 2000)));

        self::assertSame('video/webm', $chunked->complete($id)->mime());
    }

    public function testResumeFromStatusAfterAWrongOffset(): void
    {
        $video = Fixtures::mp4(2000);
        $chunked = Uploader::video($this->config())->chunked();
        $id = $chunked->start('clip.mp4', strlen($video))['upload_id'];

        $chunked->append($id, 0, self::stream(substr($video, 0, 1000)));

        // The client lost track (e.g. a timeout after the server stored the chunk) and resends
        try {
            $chunked->append($id, 0, self::stream(substr($video, 0, 1000)));
            self::fail('Expected an offset error.');
        } catch (ChunkOffsetException $e) {
            self::assertSame(1000, $e->expectedOffset());
        }

        $status = $chunked->status($id);
        self::assertSame(1000, $status['received_bytes']);
        self::assertFalse($status['complete']);

        $chunked->append($id, $status['received_bytes'], self::stream(substr($video, 1000)));
        self::assertSame($video, file_get_contents($this->root . '/' . $chunked->complete($id)->path()));
    }

    public function testRejectsForbiddenFilesBeforeAnyDataIsSent(): void
    {
        $chunked = Uploader::video($this->config())->chunked();

        foreach (['shell.php', 'movie.mkv', 'phone.mov'] as $name) {
            try {
                $chunked->start($name, 100);
                self::fail("{$name} must be rejected at start.");
            } catch (ValidationException) {
            }
        }

        self::assertSame([], glob($this->chunks . '/*') ?: []);
    }

    public function testRejectsTooLargeDeclaredSize(): void
    {
        $chunked = Uploader::video($this->config([], ['max_bytes' => 1000]))->chunked();

        $this->expectException(ValidationException::class);
        $chunked->start('clip.mp4', 1001);
    }

    public function testGenericUploaderUsesTheChunkMaxBytes(): void
    {
        $chunked = Uploader::make($this->config(['max_bytes' => 500]))->chunked();

        $this->expectException(ValidationException::class);
        $chunked->start('notes.txt', 501);
    }

    public function testRejectsOversizedChunksAndBytesPastTheEnd(): void
    {
        $chunked = Uploader::video($this->config(['max_chunk_bytes' => 100]))->chunked();
        $id = $chunked->start('clip.mp4', 150)['upload_id'];

        try {
            $chunked->append($id, 0, self::stream(str_repeat('a', 101)));
            self::fail('Expected the chunk size limit.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('maximum chunk size of 100', $e->getMessage());
        }
        self::assertSame(0, $chunked->status($id)['received_bytes'], 'a rejected chunk is not kept');

        $chunked->append($id, 0, self::stream(str_repeat('a', 100)));

        try {
            $chunked->append($id, 100, self::stream(str_repeat('a', 51)));
            self::fail('Expected the declared size limit.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('past the declared file size', $e->getMessage());
        }
        self::assertSame(100, $chunked->status($id)['received_bytes']);
    }

    public function testCompleteNeedsAllBytes(): void
    {
        $chunked = Uploader::video($this->config())->chunked();
        $id = $chunked->start('clip.mp4', 200)['upload_id'];
        $chunked->append($id, 0, self::stream(substr(Fixtures::mp4(200), 0, 100)));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('100 of 200 bytes');
        $chunked->complete($id);
    }

    public function testInvalidContentIsDiscardedOnComplete(): void
    {
        $chunked = Uploader::video($this->config())->chunked();
        $payload = '<?php system($_GET["c"]); ?>' . str_repeat(' ', 40);
        $id = $chunked->start('clip.mp4', strlen($payload))['upload_id'];
        $chunked->append($id, 0, self::stream($payload));

        try {
            $chunked->complete($id);
            self::fail('Expected a MIME validation error.');
        } catch (ValidationException) {
        }

        self::assertSame([], glob($this->chunks . '/*') ?: []);
        self::assertSame([], glob($this->root . '/*') ?: []);
        $this->expectException(ValidationException::class);
        $chunked->status($id);
    }

    public function testUploadsCanBeBoundToAnOwner(): void
    {
        $chunked = Uploader::video($this->config())->chunked();
        $id = $chunked->start('clip.mp4', 100, 'user-7')['upload_id'];

        self::assertSame(0, $chunked->status($id, 'user-7')['received_bytes']);

        foreach ([null, 'user-8'] as $other) {
            try {
                $chunked->append($id, 0, self::stream('x'), $other);
                self::fail('Another user must not write to the upload.');
            } catch (ValidationException $e) {
                self::assertSame('Unknown or expired upload.', $e->getMessage());
            }
        }
    }

    public function testRejectsMalformedIds(): void
    {
        $chunked = Uploader::video($this->config())->chunked();

        foreach (['../../etc/passwd', 'ABCDEF', str_repeat('a', 31), str_repeat('g', 32)] as $id) {
            try {
                $chunked->status($id);
                self::fail("{$id} must be rejected.");
            } catch (ValidationException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testFileNamesArePathFree(): void
    {
        $chunked = Uploader::video($this->config())->chunked();

        self::assertSame('clip.mp4', $chunked->start('../../videos/clip.mp4', 10)['filename']);
        self::assertSame('clip.mp4', $chunked->start('C:\\Users\\me\\clip.mp4', 10)['filename']);
    }

    public function testAbortAndExpiry(): void
    {
        $chunked = Uploader::video($this->config(['expire' => 3600]))->chunked();

        $aborted = $chunked->start('a.mp4', 10)['upload_id'];
        $chunked->abort($aborted);
        self::assertFileDoesNotExist($this->chunks . "/{$aborted}.part");

        $stale = $chunked->start('b.mp4', 10)['upload_id'];
        $fresh = $chunked->start('c.mp4', 10)['upload_id'];
        touch($this->chunks . "/{$stale}.json", time() - 7200);
        touch($this->chunks . "/{$stale}.part", time() - 7200);

        self::assertSame(1, $chunked->purgeExpired());
        self::assertFileDoesNotExist($this->chunks . "/{$stale}.part");
        self::assertSame(0, $chunked->status($fresh)['received_bytes']);
    }

    public function testStartReportsChunkSizes(): void
    {
        $session = Uploader::video($this->config(['chunk_size' => 50_000_000, 'max_chunk_bytes' => 2_000_000]))
            ->chunked()
            ->start('clip.mp4', 10);

        self::assertSame(2_000_000, $session['chunk_size'], 'suggested size never exceeds the maximum');
        self::assertSame(2_000_000, $session['max_chunk_bytes']);
    }

    public function testWorksWithTheImageUploader(): void
    {
        $png = Fixtures::png();
        $chunked = Uploader::image($this->config())
            ->versions(['thumb' => ['width' => 2, 'height' => 2, 'mode' => 'fit']])
            ->chunked();
        $id = $chunked->start('cover.png', strlen($png))['upload_id'];
        $chunked->append($id, 0, self::stream($png));

        $result = $chunked->complete($id, null, 'images');

        self::assertSame('image/png', $result->mime());
        self::assertNotNull($result->version('thumb'));
        self::assertFileExists($this->root . '/' . $result->path());
        self::assertSame([], glob($this->chunks . '/*') ?: []);
    }

    public function testDefaultTemporaryDirectory(): void
    {
        $config = $this->config();
        unset($config['chunks']);

        $chunked = new ChunkedUploader(Uploader::video($config));
        $id = $chunked->start('clip.mp4', 10)['upload_id'];

        self::assertFileExists(sys_get_temp_dir() . "/flocms-chunks/{$id}.json");
        $chunked->abort($id);
    }
}
