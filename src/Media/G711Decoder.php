<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;

final class G711Decoder
{
    public const PCMU = 0;
    public const PCMA = 8;
    public const SAMPLE_RATE = 8000;

    public static function decode(string $payload, int $payloadType, ?int $maxSamples = null): string
    {
        $samples = \strlen($payload);
        if ($maxSamples !== null && $maxSamples < $samples) {
            $samples = \max(0, $maxSamples);
        }
        return self::decodeRange($payload, $payloadType, 0, $samples);
    }

    /** @internal Decode a validated RTP payload directly from its datagram. */
    public static function decodeRange(string $payload, int $payloadType, int $offset, int $samples): string
    {
        static $alaw = '';
        static $mulaw = '';

        if ($payloadType === self::PCMA) {
            if ($alaw === '') {
                $alaw = self::buildTable(true);
            }
            $table = $alaw;
        } elseif ($payloadType === self::PCMU) {
            if ($mulaw === '') {
                $mulaw = self::buildTable(false);
            }
            $table = $mulaw;
        } else {
            throw new InvalidArgumentException('Expected a G.711 payload type');
        }

        if (\function_exists('spechmedia_g711_expand')) {
            return \spechmedia_g711_expand($payload, $offset, $samples, $table);
        }
        if ($offset < 0 || $samples < 0 || $offset > \strlen($payload) - $samples) {
            throw new InvalidArgumentException('Invalid G.711 payload range');
        }
        // The interpreted path shares the same table bytes as the compiled kernel.
        static $entries = [];
        if (!isset($entries[$payloadType])) {
            $entries[$payloadType] = \str_split($table, 2);
        }
        $lookup = $entries[$payloadType];
        $pcm = '';
        for ($i = $offset, $end = $offset + $samples; $i < $end; ++$i) {
            $pcm .= $lookup[\ord($payload[$i])];
        }
        return $pcm;
    }

    private static function buildTable(bool $alaw): string
    {
        $table = '';
        for ($i = 0; $i < 256; ++$i) {
            if ($alaw) {
                $code = $i ^ 0x55;
                $segment = ($code >> 4) & 7;
                $mantissa = ($code & 15) << 4;
                $sample = $segment === 0 ? $mantissa + 8 : ($mantissa + 264) << ($segment - 1);
                $sample = ($code & 128) !== 0 ? $sample : -$sample;
            } else {
                $code = $i ^ 0xff;
                $sample = ((($code & 15) * 8 + 132) << (($code >> 4) & 7)) - 132;
                $sample = ($code & 128) !== 0 ? -$sample : $sample;
            }
            $table .= \pack('v', $sample & 0xffff);
        }
        return $table;
    }
}
