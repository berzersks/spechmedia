<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use RuntimeException;
use Swoole\Coroutine\Socket;

final class UdpTransport implements SipTransport
{
    private ?Socket $socket = null;

    public function __construct(private string $localIp, private int $localPort, private string $peerIp, private int $peerPort) {}

    public function open(): void
    {
        $socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        if (!$socket->bind($this->localIp, $this->localPort) || !$socket->connect($this->peerIp, $this->peerPort)) {
            $socket->close();
            throw new RuntimeException('UDP bind/connect failed');
        }
        $this->socket = $socket;
    }

    public function send(string $data): void
    {
        if ($this->socket === null || $this->socket->send($data, 1.0) !== strlen($data)) {
            throw new RuntimeException('UDP send failed');
        }
    }

    public function receive(float $timeout): ?string
    {
        $socket = $this->socket;
        $data = $socket->recv(65535, $timeout);
        if ($data === false) {
            if ($socket->errCode === SOCKET_ETIMEDOUT) {
                return null;
            }
            throw new RuntimeException('UDP receive failed');
        }
        return $data;
    }

    public function close(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }
}
