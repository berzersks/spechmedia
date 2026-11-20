<?php
declare(strict_types=1);

namespace SpechMedia\Net;

use InvalidArgumentException;
use RuntimeException;
use Swoole\Coroutine\Socket;
use Throwable;

final class UdpEndpoint
{
    public function __construct(public readonly string $ip, public readonly int $port)
    {
        if (!self::isUnicast($ip) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Expected a concrete unicast IPv4 address and port');
        }
    }

    public static function isUnicast(string $ip): bool
    {
        $number = ip2long($ip);
        return $number !== false && long2ip($number) === $ip && ($number >> 24) > 0 && ($number >> 24) < 224;
    }

    public static function bind(string $ip, int $port): Socket
    {
        if (($ip !== '0.0.0.0' && !self::isUnicast($ip)) || $port < 0 || $port > 65535) {
            throw new InvalidArgumentException('Invalid UDP bind endpoint');
        }
        $socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        try {
            // Swoole enables address reuse inside bind(). Keep subsequent binds exclusive.
            if (!$socket->bind($ip, $port) || !$socket->setOption(SOL_SOCKET, SO_REUSEADDR, 0)) {
                throw new RuntimeException("Cannot bind UDP socket at $ip:$port");
            }
            return $socket;
        } catch (Throwable $error) {
            $socket->close();
            throw $error;
        }
    }

    public static function local(Socket $socket): self
    {
        $name = $socket->getsockname();
        if ($name === false || !self::isUnicast($name['address']) || $name['port'] < 1) {
            throw new RuntimeException('Cannot determine local UDP endpoint; specify a concrete local IPv4 address');
        }
        return new self($name['address'], $name['port']);
    }
}
