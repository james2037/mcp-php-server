<?php

namespace MCP\Server\Tests\Util;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
class MockPhpStreamWrapper
{
    public static bool $mockFwriteReturnsFalse = false;
    public static ?int $mockFwriteReturnsBytes = null;
    public static string $dataWritten = '';
    public static int $position = 0;

    /** @var resource|null */
    public $context;

    public static function reset(): void
    {
        self::$mockFwriteReturnsFalse = false;
        self::$mockFwriteReturnsBytes = null;
        self::$dataWritten = '';
        self::$position = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $opened_path = $path;
        return true;
    }

    /**
     * @param string $data
     * @return int|false Bytes written or false on error.
     */
    public function stream_write(string $data)
    {
        if (self::$mockFwriteReturnsFalse) {
            return false;
        }

        $length = strlen($data);

        if (self::$mockFwriteReturnsBytes !== null) {
            $bytesToWrite = self::$mockFwriteReturnsBytes;
            // Simulate writing only a portion if $mockFwriteReturnsBytes is less than $length
            if ($bytesToWrite > $length) { // Fixed inline control structure
                $bytesToWrite = $length; // Cannot write more than provided
            }
            self::$dataWritten .= substr($data, 0, $bytesToWrite);
            self::$position += $bytesToWrite;
            return $bytesToWrite;
        }

        self::$dataWritten .= $data;
        self::$position += $length;
        return $length; // Default: success, all bytes written
    }

    public function stream_tell(): int
    {
        return self::$position;
    }

    public function stream_eof(): bool
    {
        // For an output stream, EOF is not typically relevant in this simple mock.
        return true;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function stream_stat(): array|false
    {
        // Provide a minimal stat array.
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0, 'nlink' => 0, 'uid' => 0, 'gid' => 0,
            'rdev' => 0, 'size' => strlen(self::$dataWritten), 'atime' => 0, 'mtime' => 0,
            'ctime' => 0, 'blksize' => -1, 'blocks' => -1
        ];
    }

    // stream_seek, stream_read, etc., can be added if needed for more complex scenarios.
    // For now, only stream_write, stream_open, stream_tell, stream_eof, stream_stat are essential.

    public function stream_flush(): bool
    {
        // Mock flush operation, can be a no-op or have specific logic if needed.
        return true;
    }

    // Required for PHP 8.0+ if stream is fclose'd
    public function stream_close(): void
    {
        // No specific resource to close in this static mock
    }
}
// phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
