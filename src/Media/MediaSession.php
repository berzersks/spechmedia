<?php
declare(strict_types=1);

namespace SpechMedia\Media;

use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use SpechMedia\Net\UdpEndpoint;
use SpechMedia\Sdp\SdpSession;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class MediaSession
{
    private const BEFORE_SDP_PACKETS = 16;

    private ?AudioBuffer $buffer = null;
    private ?RtpReceiver $receiver = null;
    private bool $allowSendrecvAnswer = false;
    private ?UdpEndpoint $source = null;
    private array $payloads = [];
    private bool $awaitingAnswer = true;
    private array $beforeAnswer = [];

    public function __construct(private ?Socket $socket, private ?UdpEndpoint $bound) {}

    public function enableRecording(int $maxBytes, bool $allowSendrecvAnswer = false): void
    {
        $this->buffer = new AudioBuffer($maxBytes);
        $this->receiver = new RtpReceiver($this->buffer);
        $this->allowSendrecvAnswer = $allowSendrecvAnswer;
    }

    public function start(): void
    {
        $socket = $this->socket;
        if ($socket === null || $this->receiver === null) {
            throw new LogicException('RTP session is unavailable');
        }

        $cid = Coroutine::create(function () use ($socket): void {
            try {
                $burst = 0;
                $checkpoint = hrtime(true);
                $peer = null;
                // TypePHP needs the reference in the argument array for this extension method.
                $read = new ReflectionMethod(Socket::class, 'recvfrom');
                $args = [&$peer, -1.0];
                while ($this->socket !== null) {
                    $datagram = $read->invokeArgs($socket, $args);
                    if ($datagram === false || $this->socket === null) {
                        break;
                    }
                    if ($datagram !== '') {
                        $this->receive($datagram, $peer['address'], $peer['port']);
                    }
                    unset($datagram);
                    if (++$burst >= 32) {
                        $burst = 0;
                        $now = hrtime(true);
                        if ($now - $checkpoint < 10_000_000) {
                            // A continuously readable socket must yield to signaling.
                            Coroutine::sleep(0.001);
                        }
                        $checkpoint = $now;
                    }
                }
            } finally {
                $this->close();
            }
        });
        if ($cid === false || $this->socket === null) {
            throw new RuntimeException('Unable to start RTP receiver');
        }
    }

    public function applyAnswer(SdpSession $offer, SdpSession $answer, bool $newStream = false): void
    {
        if ($offer->direction !== 'recvonly' || $offer->port === 0) {
            throw new InvalidArgumentException('RTP reception requires a recvonly offer with an audio port');
        }
        if ($answer->port === 0) {
            throw new InvalidArgumentException('Peer rejected the audio stream (SDP port 0)');
        }
        $payloads = $answer->commonPayloads($offer);
        if (!$payloads) {
            throw new InvalidArgumentException('No common G.711 payload in SDP answer (remote PTs: '
                . implode(', ', $answer->payloads) . ')');
        }
        $compatibleSendrecv = $this->allowSendrecvAnswer && $answer->direction === 'sendrecv';
        if (!$answer->acceptsDirection($offer) && !$compatibleSendrecv) {
            throw new InvalidArgumentException('Incompatible SDP answer direction for recvonly offer: ' . $answer->direction);
        }
        $source = null;
        if ($answer->direction !== 'inactive') {
            if (!UdpEndpoint::isUnicast($answer->address)) {
                throw new InvalidArgumentException('Invalid remote SDP audio address: ' . $answer->address);
            }
            $source = new UdpEndpoint($answer->address, $answer->port);
        }
        $allowed = $source === null ? [] : array_fill_keys($payloads, true);
        if ($newStream || $source != $this->source || $allowed != $this->payloads) {
            $this->receiver?->finishStream();
        }
        $this->source = $source;
        $this->payloads = $allowed;
        $this->awaitingAnswer = false;
        foreach ($this->beforeAnswer as [$ip, $port, $packet]) {
            if ($this->matchesSource($ip, $port) && isset($allowed[$packet->payloadType])) {
                $this->receiver?->receive($packet);
            }
        }
        $this->beforeAnswer = [];
    }

    public function resetNegotiation(bool $awaitingAnswer): void
    {
        $this->receiver?->finishStream();
        $this->source = null;
        $this->payloads = [];
        $this->beforeAnswer = [];
        $this->awaitingAnswer = $awaitingAnswer;
    }

    private function matchesSource(string $ip, int $port): bool
    {
        return $this->source !== null && $ip === $this->source->ip && $port === $this->source->port;
    }

    private function receive(string $datagram, string $ip, int $port): void
    {
        if (!$this->awaitingAnswer && !$this->matchesSource($ip, $port)) {
            return;
        }
        $packet = RtpPacket::parse($datagram, G711Decoder::SAMPLE_RATE);
        if ($packet === null) {
            return;
        }
        if ($this->awaitingAnswer) {
            // RTP can win the race against SIP. Keep only a small pre-answer queue and
            // validate its source and payload against the SDP before decoding anything.
            if ($packet->payloadType !== G711Decoder::PCMA && $packet->payloadType !== G711Decoder::PCMU) {
                return;
            }
            if (count($this->beforeAnswer) >= self::BEFORE_SDP_PACKETS) {
                array_shift($this->beforeAnswer);
            }
            $this->beforeAnswer[] = [$ip, $port, $packet];
        } elseif (isset($this->payloads[$packet->payloadType])) {
            $this->receiver?->receive($packet);
        }
    }

    public function bound(): ?UdpEndpoint { return $this->bound; }
    public function pcm(): string { return $this->buffer?->pcm() ?? ''; }
    public function recordedBytes(): int { return $this->buffer?->bytes() ?? 0; }
    public function recordingTruncated(): bool { return $this->buffer?->truncated() ?? false; }

    public function close(): void
    {
        $socket = $this->socket;
        if ($socket !== null) {
            $this->socket = null;
            $this->bound = null;
            $this->resetNegotiation(false);
            $socket->close();
        }
    }
}
