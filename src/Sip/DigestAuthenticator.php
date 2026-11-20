<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use RuntimeException;

final class DigestAuthenticator
{
    private static function quote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private string $cnonce;
    private int $count = 0;

    private function __construct(private array $params, private string $algorithm, private bool $useQop)
    {
        $this->cnonce = bin2hex(random_bytes(16));
    }

    public static function fromChallenges(array $challenges): self
    {
        foreach ($challenges as $challenge) {
            if (strncasecmp($challenge, 'Digest ', 7) !== 0) {
                continue;
            }
            $params = [];
            $rest = trim(substr($challenge, 7));
            while (preg_match('/^([\w-]+)\s*=\s*(?:"((?:\\\\.|[^"\\\\])*)"|([^,\s]+))\s*(?:,\s*|$)/', $rest, $m)) {
                $key = strtolower($m[1]);
                if (isset($params[$key])) {
                    break;
                }
                $params[$key] = isset($m[3]) ? $m[3] : preg_replace('/\\\\(.)/s', '$1', $m[2]);
                $rest = substr($rest, strlen($m[0]));
            }
            $algorithm = strtoupper($params['algorithm'] ?? 'MD5');
            if ($rest !== '' || !isset($params['realm'], $params['nonce']) || $params['nonce'] === ''
                || !in_array($algorithm, ['MD5', 'MD5-SESS', 'SHA-256', 'SHA-256-SESS'], true)) {
                continue;
            }
            $useQop = isset($params['qop']);
            if ($useQop && !in_array('auth', array_map('trim', explode(',', strtolower($params['qop']))), true)) {
                continue;
            }
            if (strtolower($params['userhash'] ?? 'false') !== 'true') {
                return new self($params, $algorithm, $useQop);
            }
        }
        throw new RuntimeException('Unsupported Digest challenge');
    }

    public function authorize(string $user, string $password, string $method, string $uri): string
    {
        $hash = str_starts_with($this->algorithm, 'SHA-256') ? 'sha256' : 'md5';
        $realm = $this->params['realm'];
        $nonce = $this->params['nonce'];
        $cnonce = $this->cnonce;
        $nc = sprintf('%08x', ++$this->count);
        $a1 = hash($hash, "$user:$realm:$password");
        $session = str_ends_with($this->algorithm, '-SESS');
        if ($session) {
            $a1 = hash($hash, "$a1:$nonce:$cnonce");
        }
        $a2 = hash($hash, "$method:$uri");
        $middle = $this->useQop ? "$nonce:$nc:$cnonce:auth" : $nonce;
        $digest = hash($hash, "$a1:$middle:$a2");
        $result = 'Digest username=' . self::quote($user) . ', realm=' . self::quote($realm)
            . ', nonce=' . self::quote($nonce) . ', uri=' . self::quote($uri)
            . ', response="' . $digest . '", algorithm=' . $this->algorithm;
        if ($this->useQop) {
            $result .= ', qop=auth, nc=' . $nc;
        }
        if ($this->useQop || $session) {
            $result .= ', cnonce="' . $cnonce . '"';
        }
        if (isset($this->params['opaque'])) {
            $result .= ', opaque=' . self::quote($this->params['opaque']);
        }
        return $result;
    }
}
