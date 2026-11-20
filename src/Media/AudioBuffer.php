<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;

final class AudioBuffer
{
    public const DEFAULT_MAX_BYTES = 4_800_000; // Five minutes of mono PCM at 8 kHz.
    public const MAX_WAV_BYTES = 0xffffffda; // Largest even data size with a 36-byte RIFF overhead.
    private const BLOCK_BYTES = 16384;

    /** @var list<string> */
    private array $blocks = [];
    private string $tail = '';
    private int $bytes = 0;
    private bool $truncated = false;
    private int $maxBytes;

    public function __construct(int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 2 || $maxBytes > self::MAX_WAV_BYTES) {
            throw new InvalidArgumentException('Invalid PCM recording limit');
        }
        $this->maxBytes = $maxBytes & ~1;
    }

    public function append(RtpPacket $packet): void
    {
        $this->appendPayload($packet->payload, $packet->payloadType, 0, \strlen($packet->payload));
    }

    /** @internal Append a validated slice without creating a payload string. */
    public function appendPayload(string $data, int $payloadType, int $offset, int $length): void
    {
        $remaining = ($this->maxBytes - $this->bytes) >> 1;
        if ($length > $remaining) {
            $this->truncated = true;
            $length = $remaining;
        }
        if ($length > 0) {
            $pcm = G711Decoder::decodeRange($data, $payloadType, $offset, $length);
            $this->bytes += 2 * $length;
            $this->tail .= $pcm;
            if (\strlen($this->tail) >= self::BLOCK_BYTES) {
                $this->blocks[] = $this->tail;
                $this->tail = '';
            }
        }
    }

    public function writeWav(string $filename): bool
    {
        return WavWriter::writeBlocks($filename, $this->blocks, $this->tail);
    }

    public function pcm(): string
    {
        if (!$this->blocks) {
            return $this->tail;
        }
        $blocks = $this->blocks;
        $blocks[] = $this->tail;
        return implode('', $blocks);
    }

    public function bytes(): int { return $this->bytes; }
    public function truncated(): bool { return $this->truncated; }
}
