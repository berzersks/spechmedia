<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use InvalidArgumentException;

final class SipMessage
{
    private array $headers = [];

    /** Each header entry is a [name, value] pair, in wire order. */
    public function __construct(public readonly string $line, array $fields, public readonly string $body = '')
    {
        if (!preg_match('/^[\x20-\x7e\x80-\xff]+$/D', $line)) {
            throw new InvalidArgumentException('Invalid SIP start line');
        }
        foreach ($fields as [$name, $value]) {
            if (!preg_match("/^[a-zA-Z0-9.!%*_+\x60'~-]+$/D", $name) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Invalid SIP header');
            }
            $this->headers[self::canonical($name)][] = $value;
        }
    }

    public static function canonical(string $name): string
    {
        $key = strtolower($name);
        return match ($key) {
            'v' => 'via', 'i' => 'call-id', 'f' => 'from', 't' => 'to',
            'm' => 'contact', 'l' => 'content-length', 'c' => 'content-type',
            default => $key
        };
    }

    public function values(string $name): array
    {
        return $this->headers[self::canonical($name)] ?? [];
    }

    public function header(string $name): string
    {
        return $this->headers[self::canonical($name)][0] ?? '';
    }

    public function status(): int
    {
        return str_starts_with($this->line, 'SIP/2.0 ') ? (int) substr($this->line, 8, 3) : 0;
    }

    public function method(): string
    {
        return explode(' ', $this->header('cseq'), 2)[1] ?? '';
    }

    public function branch(): string
    {
        return self::parameter(explode(',', $this->header('via'), 2)[0], 'branch');
    }

    public static function parameter(string $field, string $name): string
    {
        $end = strrpos($field, '>');
        $params = $end === false ? $field : substr($field, $end + 1);
        preg_match('/;[ \t]*' . preg_quote($name, '/') . '=([^;,\s]+)/i', $params, $matches);
        return $matches[1] ?? '';
    }

    public function wire(): string
    {
        $data = $this->line . "\r\n";
        foreach ($this->headers as $name => $values) {
            if ($name !== 'content-length') {
                foreach ($values as $value) {
                    $data .= $name . ': ' . $value . "\r\n";
                }
            }
        }
        return $data . 'Content-Length: ' . strlen($this->body) . "\r\n\r\n" . $this->body;
    }
}
