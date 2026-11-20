<?php
declare(strict_types=1);

namespace SpechMedia\Media;

/** Reorders one SSRC in a bounded sequence window before decoding its payloads. */
final class RtpReceiver
{
    private const WINDOW = 16;

    private ?int $ssrc = null;
    private int $next = 0;
    private bool $starting = true;
    /** @var array<int, RtpPacket> Indexed by extended sequence number. */
    private array $pending = [];

    public function __construct(private AudioBuffer $buffer) {}

    public function receive(RtpPacket $packet): void
    {
        if ($this->ssrc === null) {
            $this->ssrc = $packet->ssrc;
            $this->next = $packet->sequence;
            $this->pending[$this->next] = $packet;
            return;
        }
        if ($this->ssrc !== $packet->ssrc) {
            return;
        }

        $delta = ($packet->sequence - $this->next + 32768) & 65535;
        $delta -= 32768;
        $index = $this->next + $delta;
        if (isset($this->pending[$index]) || ($delta < 0 && (!$this->starting || $delta <= -self::WINDOW))) {
            return;
        }
        if ($this->starting) {
            $this->starting = false;
            $this->next = min($this->next, $index);
        }
        if ($index - $this->next >= self::WINDOW) {
            // Release packets older than the new window, skipping any holes.
            $this->next = $index - self::WINDOW + 1;
            $this->flushBefore($this->next);
        }
        $this->pending[$index] = $packet;
        while (isset($this->pending[$this->next])) {
            $this->buffer->append($this->pending[$this->next]);
            unset($this->pending[$this->next]);
            ++$this->next;
        }
    }

    private function flushBefore(int $sequence): void
    {
        ksort($this->pending, SORT_NUMERIC);
        foreach ($this->pending as $index => $packet) {
            if ($index >= $sequence) {
                break;
            }
            $this->buffer->append($packet);
            unset($this->pending[$index]);
        }
    }

    public function finishStream(): void
    {
        $this->flushBefore(PHP_INT_MAX);
        $this->ssrc = null;
        $this->starting = true;
    }
}
