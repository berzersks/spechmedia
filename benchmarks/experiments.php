<?php
declare(strict_types=1);

use SpechMedia\Media\AudioBuffer;
use SpechMedia\Media\G711Decoder;
use SpechMedia\Media\MediaSession;
use SpechMedia\Media\WavWriter;
use SpechMedia\Net\UdpEndpoint;
use SpechMedia\Sdp\SdpSession;
use Swoole\Coroutine;

function compareTables(): void
{
    $fixed = std::array(Type::String, 256);
    $map = [];
    $alphabet = '';
    $pt = (int) getenv('PT');
    for ($i = 0; $i < 256; ++$i) {
        $code = chr($i);
        $entry = G711Decoder::decode($code, $pt);
        $map[$code] = $entry;
        $fixed[$i] = $entry;
        $alphabet .= $code;
    }
    $samples = (int) getenv('PTIME') * 8;
    $payload = substr(str_repeat($alphabet, 2), 0, $samples);
    $expected = G711Decoder::decode($payload, $pt);
    foreach (['fixed', 'strtr', 'kernel'] as $method) {
        $times = [];
        for ($trial = 0; $trial < 7; ++$trial) {
            $start = hrtime(true);
            for ($r = 0; $r < 4096; ++$r) {
                if ($method === 'fixed') {
                    $pcm = '';
                    for ($i = 0; $i < $samples; ++$i) {
                        $pcm .= $fixed[ord($payload[$i])];
                    }
                } elseif ($method === 'strtr') {
                    $pcm = strtr($payload, $map);
                } else {
                    $pcm = G711Decoder::decode($payload, $pt);
                }
            }
            $times[] = (hrtime(true) - $start) / 4096;
            if ($pcm !== $expected) {
                throw new RuntimeException('Codec mismatch');
            }
        }
        sort($times);
        echo implode(',', [$pt, $samples, $method, round($times[3])]) . "\n";
    }
}

function exportRecording(bool $segmented): void
{
    $buffer = new AudioBuffer(64_000_000);
    $payload = str_repeat("\xd5", 8000);
    for ($i = 0; $i < 4000; ++$i) {
        $buffer->appendPayload($payload, 8, 0, 8000);
    }
    $filename = tempnam(sys_get_temp_dir(), 'wav-bench-');
    try {
        memory_reset_peak_usage();
        $start = memory_get_usage();
        if ($segmented) {
            $buffer->writeWav($filename);
        } else {
            WavWriter::write($filename, $buffer->pcm());
        }
        echo (memory_get_peak_usage() - $start) . ',' . getrusage()['ru_maxrss'] . ','
            . hash_file('sha256', $filename) . "\n";
    } finally {
        unlink($filename);
    }
}

// One hot socket. Queue a burst, then probe scheduler responsiveness while the
// receiver drains it. Only the test driver polls. Include send time in throughput.
function burstTest(): void
{
    $rx = UdpEndpoint::bind('127.0.0.1', 0);
    $tx = UdpEndpoint::bind('127.0.0.1', 0);
    $local = UdpEndpoint::local($rx);
    $peer = UdpEndpoint::local($tx);
    $media = new MediaSession($rx, $local);
    $media->enableRecording(128 * 128 * 320);
    $media->applyAnswer(new SdpSession($local->ip, $local->port, [8], 20, 'recvonly'),
        new SdpSession($peer->ip, $peer->port, [8], 20, 'sendonly'));
    $media->start();
    $payload = str_repeat("\xd5", 160);
    $wakes = [];
    $start = hrtime(true);
    for ($batch = 0; $batch < 128; ++$batch) {
        for ($i = 0; $i < 128; ++$i) {
            $seq = $batch * 128 + $i;
            $wire = pack('CCnNN', 128, 8, $seq, $seq * 160, 42) . $payload;
            $tx->sendto($local->ip, $local->port, $wire);
        }
        $last = hrtime(true);
        do {
            Coroutine::sleep(0.001);
            $now = hrtime(true);
            $wakes[] = $now - $last;
            $last = $now;
            if ($now - $start > 10_000_000_000) {
                throw new RuntimeException('RTP drain deadline exceeded');
            }
        } while ($media->recordedBytes() < ($batch + 1) * 128 * 320);
    }
    $elapsed = hrtime(true) - $start;
    $media->close();
    $tx->close();
    sort($wakes, SORT_NUMERIC);
    echo round(16384 * 1e9 / $elapsed) . ',' . round($wakes[(int) (count($wakes) * 0.95)] / 1000)
        . ',' . round(max($wakes) / 1000) . "\n";
}

function main(): void
{
    ini_set('memory_limit', '512M');
    $mode = (string) getenv('MODE');
    if ($mode === 'burst') {
        Coroutine::set(['hook_flags' => 0]);
        Swoole\Coroutine\run('burstTest');
    } elseif ($mode === 'segmented' || $mode === 'flat') {
        exportRecording($mode === 'segmented');
    } else {
        compareTables();
    }
}
