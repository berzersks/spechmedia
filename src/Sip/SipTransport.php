<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use SpechMedia\Net\UdpEndpoint;

interface SipTransport
{
    /** Return the effective local endpoint of the connected transport. */
    public function open(string $localIp, int $localPort, UdpEndpoint $server): UdpEndpoint;
    public function send(string $data): void;
    /** Null on poll timeout. Transport errors throw. */
    public function receive(float $timeout): ?string;
    public function close(): void;
}
