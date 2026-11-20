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
    private readonly int $maxBytes;

    public function __construct(int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 2 || $maxBytes > self::MAX_WAV_BYTES) {
            throw new InvalidArgumentException('Invalid PCM recording limit');
        }
        $this->maxBytes = $maxBytes & ~1;
    }

    public function append(RtpPacket $packet): void
    {
        $remaining = intdiv($this->maxBytes - $this->bytes, 2);
        if (strlen($packet->payload) > $remaining) {
            $this->truncated = true;
        }
        if ($remaining > 0) {
            $pcm = G711Decoder::decode($packet->payload, $packet->payloadType, $remaining);
            $this->bytes += strlen($pcm);
            $this->tail .= $pcm;
            if (strlen($this->tail) >= self::BLOCK_BYTES) {
                $this->blocks[] = $this->tail;
                $this->tail = '';
            }
        }
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
