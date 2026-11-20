<?php
declare(strict_types=1);

use SpechMedia\Media\AudioBuffer;
use SpechMedia\Media\G711Decoder;
use SpechMedia\Media\MediaSession;
use SpechMedia\Media\RtpPacket;
use SpechMedia\Media\RtpReceiver;
use SpechMedia\Net\UdpEndpoint;
use SpechMedia\Sdp\SdpSession;
use Swoole\Coroutine;

function rssBytes(): int
{
    $status = file_get_contents('/proc/self/status');
    $line = explode('VmRSS:', $status, 2);
    return (int) trim($line[1]) * 1024;
}

function cpuMicros(): int
{
    $usage = getrusage();
    return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000000
        + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
}

function runMediaBench(): void
{
    $mode = (string) getenv('MODE');
    $calls = max(1, (int) getenv('CALLS'));
    $pt = (int) getenv('PT');
    $samples = 8 * (int) getenv('PTIME');
    $rounds = max(64, intdiv(8192, $calls));
    $alphabet = '';
    for ($i = 0; $i < 256; ++$i) {
        $alphabet .= chr($i);
    }
    // SHA-256 of all 256 PCM entries from the original decoder (376acdc).
    $digest = $pt === 8 ? 'e04788d110e58ff8c70c93b8480190d973e3b67876b6119abbaec766cc75c174'
        : '3dab54339e520bb2c924826e3b72a917a2b612e9fd12fc867500f1d983a75827';
    if (hash('sha256', G711Decoder::decode($alphabet, $pt)) !== $digest) {
        throw new RuntimeException('G.711 reference mismatch');
    }
    $payload = substr(str_repeat($alphabet, 2), 0, $samples);
    $expected = G711Decoder::decode($payload, $pt);
    $packets = [];
    for ($r = 0; $r < $rounds; ++$r) {
        $packets[] = pack('CCnNN', 128, $pt, $r, $r * $samples, 0xfedcba98) . $payload;
    }

    $sessions = [];
    $buffers = [];
    $ports = [];
    $sender = null;
    if ($mode === 'udp') {
        $sender = UdpEndpoint::bind('127.0.0.1', 0);
    }
    $base = rssBytes();
    for ($i = 0; $i < $calls; ++$i) {
        if ($mode === 'udp') {
            $socket = UdpEndpoint::bind('127.0.0.1', 0);
            $port = (int) $socket->getsockname()['port'];
            $endpoint = new UdpEndpoint('127.0.0.1', $port);
            $session = new MediaSession($socket, $endpoint);
            $session->enableRecording($rounds * $samples * 2);
            $session->applyAnswer(new SdpSession($endpoint->ip, $port, [$pt], 20, 'recvonly'),
                new SdpSession($endpoint->ip, $sender->getsockname()['port'], [$pt], 20, 'sendonly'));
            $session->start();
            $ports[] = $port;
            $sessions[] = $session;
        } elseif ($mode === 'pipeline') {
            $buffer = new AudioBuffer($rounds * $samples * 2);
            $buffers[] = $buffer;
            $sessions[] = new RtpReceiver($buffer);
        }
    }
    $idle = rssBytes();
    $cost = [];
    $cpu = cpuMicros();
    $start = hrtime(true);
    for ($r = 0; $r < $rounds; ++$r) {
        $wire = $packets[$r];
        $begin = hrtime(true);
        for ($i = 0; $i < $calls; ++$i) {
            if ($mode === 'pipeline') {
                $sessions[$i]->receiveDatagram($wire);
            } elseif ($mode === 'udp') {
                if ($sender->sendto('127.0.0.1', $ports[$i], $wire) !== strlen($wire)) {
                    throw new RuntimeException('UDP send failed');
                }
            } elseif ($mode === 'parse') {
                $packet = RtpPacket::parse($wire, 8000);
            } else {
                $pcm = G711Decoder::decode($payload, $pt);
            }
        }
        // Bound the offered burst per socket. Include sender and scheduler in UDP cost.
        if ($mode === 'udp' && ($r & 15) === 15) {
            Coroutine::sleep(0.001);
        }
        $cost[] = (hrtime(true) - $begin) / $calls;
    }
    if ($mode === 'udp') {
        $deadline = microtime(true) + 3.0;
        do {
            $complete = true;
            foreach ($sessions as $media) {
                if ($media->recordedBytes() !== $rounds * $samples * 2) {
                    $complete = false;
                    break;
                }
            }
            if (!$complete) {
                Coroutine::sleep(0.001);
            }
        } while (!$complete && microtime(true) < $deadline);
    }
    $elapsed = hrtime(true) - $start;
    $cpu = cpuMicros() - $cpu;
    $peak = getrusage()['ru_maxrss'];
    $lost = 0;
    $full = str_repeat($expected, $rounds);
    foreach ($sessions as $i => $item) {
        if ($mode === 'udp') {
            $item->close();
            $actual = $item->pcm();
        } else {
            $item->finishStream();
            $actual = $buffers[$i]->pcm();
        }
        $lost += $rounds - intdiv(strlen($actual), $samples * 2);
        if ($actual !== substr($full, 0, strlen($actual))) {
            throw new RuntimeException('PCM mismatch');
        }
    }
    sort($cost, SORT_NUMERIC);
    $total = $calls * $rounds;
    echo implode(',', [$mode, $pt, intdiv($samples, 8), $calls, $total, round($total * 1e9 / $elapsed),
        round($elapsed / $total), round($cost[intdiv($rounds, 2)]), round($cost[(int) ($rounds * 0.95)]),
        round($cpu * 1000 / $total), $peak, round(($idle - $base) / $calls), $lost, $cpu, $elapsed]) . "\n";
}

function main(): void
{
    ini_set('memory_limit', '512M');
    if (getenv('MODE') === 'udp') {
        Coroutine::set(['hook_flags' => 0]);
        Swoole\Coroutine\run('runMediaBench');
    } else {
        runMediaBench();
    }
}
