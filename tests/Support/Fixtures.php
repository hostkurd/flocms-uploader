<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Tests\Support;

/**
 * Small files with real signatures, detected by finfo like the real formats.
 */
final class Fixtures
{
    /** @var list<string> */
    private static array $files = [];

    public static function mp4(int $padding = 64): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . "\x00\x00\x00\x08free" . str_repeat("\0", $padding);
    }

    public static function webm(int $padding = 64): string
    {
        return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08"
            . "\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02" . str_repeat("\0", $padding);
    }

    public static function mov(int $padding = 64): string
    {
        return "\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00qt  " . str_repeat("\0", $padding);
    }

    public static function png(): string
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string) ob_get_clean();
    }

    /**
     * A $_FILES-style entry backed by a temporary file.
     */
    public static function upload(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'flo-fixture-');
        file_put_contents($path, $contents);
        self::$files[] = $path;

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        self::$files[] = $dir;
        return $dir;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$files) as $path) {
            self::remove($path);
        }
        self::$files = [];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }

        @unlink($path);
    }
}
