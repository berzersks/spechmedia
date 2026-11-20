<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use InvalidArgumentException;

final class SipDialog
{
    private array $routes;
    public readonly string $target;
    public readonly string $to;
    private int $sequence;

    public function __construct(SipMessage $answer, int $sequence)
    {
        $this->sequence = $sequence;
        $this->to = $answer->header('to');
        if (SipMessage::parameter($this->to, 'tag') === '') {
            throw new InvalidArgumentException('Missing dialog tag');
        }
        $this->target = self::uri($answer->header('contact'));
        $routes = [];
        foreach ($answer->values('record-route') as $field) {
            foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<>]*>)/', $field) as $route) {
                self::uri($route);
                $routes[] = trim($route);
            }
        }
        $this->routes = array_reverse($routes);
    }

    public static function uri(string $address): string
    {
        if (!preg_match('/<([^<>\s]+)>/', $address, $m)) {
            $uri = trim($address);
        } else {
            $uri = $m[1];
        }
        if (!preg_match('/^sip:[^<>"\s,]+$/iD', $uri)) {
            throw new InvalidArgumentException('Unsupported SIP URI');
        }
        return $uri;
    }

    public function nextSequence(): int
    {
        return ++$this->sequence;
    }

    /** Strict routing moves the first route into the request URI. */
    public function routing(): array
    {
        $routes = $this->routes;
        $uri = $this->target;
        if ($routes && !preg_match('/;lr(?:[;=?]|$)/i', self::uri($routes[0]))) {
            $uri = self::uri(array_shift($routes));
            $routes[] = '<' . $this->target . '>';
        }
        return [$uri, $routes];
    }
}
