<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

interface SipTransport
{
    public function open(): void;
    public function send(string $data): void;
    /** Null on poll timeout. Transport errors throw. */
    public function receive(float $timeout): ?string;
    public function close(): void;
}
