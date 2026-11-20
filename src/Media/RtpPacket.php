<?php
declare(strict_types=1);

namespace SpechMedia\Media;

final class RtpPacket
{
    private function __construct(
        public readonly int $payloadType,
        public readonly int $sequence,
        public readonly int $timestamp,
        public readonly int $ssrc,
        public readonly string $payload
    ) {}

    public static function parse(string $datagram, int $maxPayload = 65507): ?self
    {
        $length = \strlen($datagram);
        if ($length < 12) {
            return null;
        }
        $flags = \ord($datagram[0]);
        if (($flags >> 6) !== 2) {
            return null;
        }

        $offset = 12 + 4 * ($flags & 15);
        if ($offset >= $length) {
            return null;
        }
        if (($flags & 16) !== 0) {
            if ($length - $offset < 4) {
                return null;
            }
            $words = (\ord($datagram[$offset + 2]) << 8) | \ord($datagram[$offset + 3]);
            $offset += 4 + 4 * $words;
        }
        if (($flags & 32) !== 0) {
            $padding = \ord($datagram[$length - 1]);
            if ($padding === 0) {
                return null;
            }
            $length -= $padding;
        }
        $size = $length - $offset;
        if ($size < 1 || $size > $maxPayload) {
            return null;
        }

        $header = \unpack('nsequence/Ntimestamp/Nssrc', $datagram, 2);
        return new self(\ord($datagram[1]) & 127, $header['sequence'], $header['timestamp'], $header['ssrc'],
            \substr($datagram, $offset, $size));
    }
}
