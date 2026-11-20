<?php
declare(strict_types=1);

namespace SpechMedia\Sdp;

use InvalidArgumentException;

final class SdpSession
{
    private string $description;

    public function __construct(
        public readonly string $address,
        public readonly int $port,
        public readonly array $payloads = [8, 0],
        public readonly int $ptime = 20,
        public readonly string $direction = 'sendrecv'
    ) {
        if (ip2long($address) === false
            || $port < 0 || $port > 65535 || $ptime < 1 || $ptime > 1000
            || !in_array($direction, ['sendrecv', 'sendonly', 'recvonly', 'inactive'], true)
            || !$payloads || count($payloads) > 128) {
            throw new InvalidArgumentException('Invalid audio description');
        }
        foreach ($payloads as $pt) {
            if (!is_int($pt) || $pt < 0 || $pt > 127) {
                throw new InvalidArgumentException('Invalid payload type');
            }
        }
        $id = random_int(1, 2147483647);
        $this->description = "v=0\r\no=- $id 1 IN IP4 $address\r\ns=-\r\nc=IN IP4 $address\r\nt=0 0\r\n"
            . "m=audio $port RTP/AVP " . implode(' ', $payloads) . "\r\n";
        foreach ($payloads as $pt) {
            if ($pt === 8 || $pt === 0) {
                $codec = $pt === 8 ? 'PCMA' : 'PCMU';
                $this->description .= "a=rtpmap:$pt $codec/8000\r\n";
            }
        }
        $this->description .= "a=ptime:$ptime\r\na=$direction\r\n";
    }

    public function wire(): string
    {
        return $this->description;
    }

    public static function parse(string $sdp): self
    {
        if (strlen($sdp) > 32768 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|\r(?!\n)/', $sdp) || !preg_match('/^v=0\r?\n/', $sdp)) {
            throw new InvalidArgumentException('Invalid SDP');
        }
        $hasOrigin = false;
        $hasName = false;
        $hasTime = false;
        $address = '';
        $port = -1;
        $pts = [];
        $ptime = 20;
        $direction = 'sendrecv';
        foreach (explode("\n", rtrim($sdp, "\r\n")) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, 'm=')) {
                if (!$hasOrigin || !$hasName || !$hasTime || $port >= 0 || !preg_match('/^m=audio ([0-9]+) RTP\/AVP ((?:[0-9]+ )*[0-9]+)$/D', $line, $m)) {
                    throw new InvalidArgumentException('Expected one RTP/AVP audio stream');
                }
                $port = (int) $m[1];
                $pts = array_map('intval', explode(' ', $m[2]));
            } elseif (str_starts_with($line, 'o=')) {
                $hasOrigin = preg_match('/^o=\S+ [0-9]+ [0-9]+ IN IP4 \S+$/D', $line) === 1;
            } elseif (str_starts_with($line, 's=')) {
                $hasName = strlen($line) > 2;
            } elseif (str_starts_with($line, 't=')) {
                $hasTime = preg_match('/^t=[0-9]+ [0-9]+$/D', $line) === 1;
            } elseif (str_starts_with($line, 'c=')) {
                if (!str_starts_with($line, 'c=IN IP4 ')) {
                    throw new InvalidArgumentException('Expected IPv4 SDP connection');
                }
                $address = substr($line, 9);
            } elseif (str_starts_with($line, 'a=ptime:')) {
                if (!preg_match('/^a=ptime:([0-9]+)$/D', $line, $m)) {
                    throw new InvalidArgumentException('Invalid ptime');
                }
                $ptime = (int) $m[1];
            } elseif (in_array($line, ['a=sendrecv', 'a=inactive', 'a=sendonly', 'a=recvonly'], true)) {
                $direction = substr($line, 2);
            } elseif (preg_match('/^a=rtpmap:([0-9]+)(?:\s+(.*))?$/iD', $line, $m)
                && in_array((int) $m[1], [0, 8], true)) {
                $codec = (int) $m[1] === 8 ? 'PCMA' : 'PCMU';
                if (!in_array(strtoupper($m[2] ?? ''), [$codec . '/8000', $codec . '/8000/1'], true)) {
                    throw new InvalidArgumentException('Invalid static payload mapping');
                }
            }
        }
        $session = new self($address, $port, $pts, $ptime, $direction);
        $session->description = $sdp;
        return $session;
    }

    public function acceptsDirection(self $offer): bool
    {
        return match ($offer->direction) {
            'inactive' => $this->direction === 'inactive',
            'sendonly' => in_array($this->direction, ['recvonly', 'inactive'], true),
            'recvonly' => in_array($this->direction, ['sendonly', 'inactive'], true),
            default => true
        };
    }

    /** Supported G.711 payloads common to the offer and answer, in answer order. */
    public function commonPayloads(self $offer): array
    {
        return $this->port && $offer->port ? array_values(array_intersect($this->payloads, $offer->payloads, [0, 8])) : [];
    }
}
