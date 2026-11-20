<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;
use RuntimeException;

final class WavWriter
{
    public static function write(string $filename, string $pcm): bool
    {
        return self::writeBlocks($filename, [], $pcm);
    }

    /** @internal The arguments form a copy-on-write snapshot if I/O yields. */
    public static function writeBlocks(string $filename, array $blocks, string $tail): bool
    {
        $bytes = strlen($tail);
        foreach ($blocks as $block) {
            $bytes += strlen($block);
        }
        if ($bytes === 0) {
            return false;
        }
        if (($bytes & 1) !== 0 || $bytes > AudioBuffer::MAX_WAV_BYTES) {
            throw new InvalidArgumentException('Expected complete 16-bit PCM samples within the RIFF size limit');
        }

        $rate = G711Decoder::SAMPLE_RATE;
        $header = 'RIFF' . pack('V', 36 + $bytes) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, $rate, 2 * $rate, 2, 16)
            . 'data' . pack('V', $bytes);
        $file = @fopen($filename, 'wb');
        if ($file === false) {
            throw new RuntimeException("Cannot open WAV file $filename");
        }
        try {
            self::writeAll($file, $header);
            foreach ($blocks as $block) {
                self::writeAll($file, $block);
            }
            if ($tail !== '') {
                self::writeAll($file, $tail);
            }
            if (!fflush($file)) {
                throw new RuntimeException('Cannot flush WAV file');
            }
        } finally {
            fclose($file);
        }
        return true;
    }

    /** @param resource $file */
    private static function writeAll($file, string $data): void
    {
        $size = strlen($data);
        $written = @fwrite($file, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Cannot write WAV file');
        }
        while ($written < $size) {
            $count = @fwrite($file, substr($data, $written, 65536));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Cannot write WAV file');
            }
            $written += $count;
        }
    }
}
