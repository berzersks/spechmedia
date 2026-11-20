<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;
use RuntimeException;

final class WavWriter
{
    public static function write(string $filename, string $pcm): bool
    {
        $bytes = strlen($pcm);
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
            self::writeAll($file, $pcm);
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
