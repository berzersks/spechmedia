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
        static $alaw = [];
        static $mulaw = [];

        if ($payloadType === self::PCMA) {
            if (!$alaw) {
                $alaw = self::buildTable(true);
            }
            $table = $alaw;
        } elseif ($payloadType === self::PCMU) {
            if (!$mulaw) {
                $mulaw = self::buildTable(false);
            }
            $table = $mulaw;
        } else {
            throw new InvalidArgumentException('Expected a G.711 payload type');
        }

        $pcm = '';
        $samples = $maxSamples === null ? strlen($payload) : min(strlen($payload), $maxSamples);
        for ($i = 0; $i < $samples; ++$i) {
            $pcm .= $table[ord($payload[$i])];
        }
        return $pcm;
    }

    private static function buildTable(bool $alaw): array
    {
        $table = [];
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
            $table[] = pack('v', $sample & 0xffff);
        }
        return $table;
    }
}
