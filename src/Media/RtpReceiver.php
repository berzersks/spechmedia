<?php
declare(strict_types=1);

namespace SpechMedia\Media;

/** Reorders one SSRC in a bounded sequence window before decoding its payloads. */
final class RtpReceiver
{
    private const WINDOW = 16;

    private ?int $ssrc = null;
    private string $ssrcBytes = '';
    private int $next = 0;
    private bool $starting = true;
    /** @var array<int, RtpPacket> Indexed by extended sequence number. */
    private array $pending = [];

    public function __construct(private AudioBuffer $buffer) {}

    /** @internal The session has already checked the source and negotiated PT. */
    public function receiveDatagram(string $datagram): void
    {
        $length = \strlen($datagram);
        if ($length <= 12) {
            return;
        }
        if (!$this->starting && $datagram[0] === "\x80") {
            // Fixed RTP v2 header, no CSRC, extension or padding. SSRC is compared
            // in network order. The timestamp is not needed for sequence ordering.
            if ($length - 12 > G711Decoder::SAMPLE_RATE || \substr_compare($datagram, $this->ssrcBytes, 8, 4) !== 0) {
                return;
            }
            $sequence = (\ord($datagram[2]) << 8) | \ord($datagram[3]);
            if ($sequence === ($this->next & 65535)) {
                $this->buffer->appendPayload($datagram, \ord($datagram[1]) & 127, 12, $length - 12);
                ++$this->next;
                if ($this->pending) {
                    $this->drain();
                }
                return;
            }
        }

        $packet = RtpPacket::parse($datagram, G711Decoder::SAMPLE_RATE);
        if ($packet !== null) {
            $this->receive($packet);
        }
    }

    public function receive(RtpPacket $packet): void
    {
        if ($this->ssrc === null) {
            $this->ssrc = $packet->ssrc;
            $this->ssrcBytes = \pack('N', $packet->ssrc);
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
        $this->drain();
    }

    private function drain(): void
    {
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
