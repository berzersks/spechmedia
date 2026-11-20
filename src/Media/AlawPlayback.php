<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SpechMedia\Net\UdpEndpoint;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

final class AlawPlayback
{
    private ?Channel $stop = null;

    public function __construct(private string $samples)
    {
        if ($samples === '') {
            throw new InvalidArgumentException('Empty PCMA playback');
        }
    }

    /** Prepare the immutable RTP payload bytes before starting the SIP event loop. */
    public static function loadWav(string $filename): string
    {
        $path = realpath($filename);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read playback WAV: $filename");
        }
        $pipes = [];
        $process = proc_open(['ffmpeg', '-nostdin', '-v', 'error', '-xerror', '-f', 'wav', '-i', $path,
            '-map', '0:a:0', '-ac', '1', '-ar', '8000', '-c:a', 'pcm_alaw', '-f', 'alaw', 'pipe:1'],
            [1 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Cannot start ffmpeg for WAV playback');
        }
        try {
            $audio = stream_get_contents($pipes[1]);
        } finally {
            fclose($pipes[1]);
            $status = proc_close($process);
        }
        if ($status !== 0 || $audio === false || $audio === '') {
            throw new RuntimeException("Cannot convert WAV to PCMA: $filename");
        }
        return $audio;
    }

    /** @internal One sender per dialog, using the same bound port as the receiver. */
    public function start(Socket $socket, UdpEndpoint $peer, int $ptime): void
    {
        if ($this->stop !== null || $ptime < 1 || $ptime > 1000) {
            throw new LogicException('Invalid playback state or packet duration');
        }
        $stop = new Channel(1);
        $this->stop = $stop;
        $cid = \Swoole\Coroutine::create(function () use ($socket, $peer, $ptime, $stop): void {
            try {
                $samples = $ptime * 8;
                $period = $ptime * 1_000_000;
                $audio = $this->samples;
                $length = \strlen($audio);
                if ($length < $samples) {
                    $audio = \str_repeat($audio, \intdiv($samples + $length - 1, $length));
                    $length = \strlen($audio);
                }
                $offset = 0;
                $sequence = \random_int(0, 65535);
                $timestamp = \random_int(0, 0xffffffff);
                $ssrc = \random_int(0, 0xffffffff);
                $pt = 0x88; // Marker on the first PCMA packet.
                $deadline = \hrtime(true);
                while ($this->stop !== null) {
                    // Skip elapsed frames after a scheduler stall instead of sending a backlog.
                    $lateFrames = \intdiv(\max(0, \hrtime(true) - $deadline), $period);
                    $offset = ($offset + $lateFrames * $samples) % $length;
                    $timestamp = ($timestamp + $lateFrames * $samples) & 0xffffffff;
                    $payload = \substr($audio, $offset, $samples);
                    $missing = $samples - \strlen($payload);
                    if ($missing > 0) {
                        $payload .= \substr($audio, 0, $missing);
                    }
                    $packet = \pack('CCnNN', 0x80, $pt, $sequence, $timestamp, $ssrc) . $payload;
                    if ($socket->sendto($peer->ip, $peer->port, $packet) !== \strlen($packet)) {
                        if ($this->stop !== null) {
                            error_log('SpechMedia RTP playback send failed');
                        }
                        break;
                    }
                    $pt = 8;
                    $sequence = ($sequence + 1) & 65535;
                    $timestamp = ($timestamp + $samples) & 0xffffffff;
                    $offset = ($offset + $samples) % $length;
                    $deadline += ($lateFrames + 1) * $period;
                    $delay = ($deadline - \hrtime(true)) / 1e9;
                    if ($delay > 0 && $this->stop !== null) {
                        $stop->pop($delay);
                    }
                }
            } catch (\Throwable $error) {
                if ($this->stop !== null) {
                    error_log('SpechMedia RTP playback stopped: ' . $error->getMessage());
                }
            } finally {
                $this->stop = null;
            }
        });
        if ($cid === false) {
            $this->stop = null;
            throw new RuntimeException('Cannot start RTP playback');
        }
    }

    public function stop(): void
    {
        $stop = $this->stop;
        $this->stop = null;
        $stop?->close();
    }
}
