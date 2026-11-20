<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use RuntimeException;
use SpechMedia\Net\UdpEndpoint;
use Throwable;
use Swoole\Coroutine\Socket;

final class UdpTransport implements SipTransport
{
    private ?Socket $socket = null;

    public function open(string $localIp, int $localPort, UdpEndpoint $server): UdpEndpoint
    {
        $socket = UdpEndpoint::bind($localIp, $localPort);
        try {
            if (!$socket->connect($server->ip, $server->port)) {
                throw new RuntimeException("Cannot connect UDP transport to {$server->ip}:{$server->port}");
            }
            $local = UdpEndpoint::local($socket);
            $this->socket = $socket;
            return $local;
        } catch (Throwable $error) {
            $socket->close();
            throw $error;
        }
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
