<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SpechMedia\Media\G711Decoder;
use SpechMedia\Net\UdpEndpoint;
use SpechMedia\Sdp\SdpSession;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;
use Throwable;
use WeakMap;

final class SipUserAgent
{
    private SipTransport $transport;
    private bool $running = false;
    private int $generation = 0;
    private array $transactions = [];
    private array $calls = [];
    private array $serverReplies = [];
    private string $registrationId;
    private string $registrationTag;
    private int $registrationSeq = 0;
    private bool $registering = false;
    private string $identity;
    private string $contact;
    private string $sentBy;

    private string $domain;
    private ?UdpEndpoint $local = null;
    private ?UdpEndpoint $peer = null;
    private ?UdpEndpoint $advertised = null;
    private bool $opening = false;
    /** Calls with sockets reserved before invite(). Discarding a draft frees its socket. */
    private WeakMap $prepared;

    public function __construct(
        private string $server, private string $username, private string $password, private int $serverPort = 5060,
        private string $localIp = '0.0.0.0', private int $localPort = 0,
        private ?string $advertisedIp = null, private ?int $advertisedPort = null,
        ?string $domain = null, ?SipTransport $transport = null, private float $t1 = 0.5,
        private float $progressTimeout = 180.0, private bool $log = false,
        private int $maxCalls = 64, private int $maxTransactions = 512
    ) {
        if ($maxCalls < 1 || $maxTransactions < 1) {
            throw new InvalidArgumentException('Expected positive SIP capacity limits');
        }
        $this->domain = $domain ?? ($serverPort === 5060 ? $server : "$server:$serverPort");
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $server)
            || ($localIp !== '0.0.0.0' && !UdpEndpoint::isUnicast($localIp))
            || ($advertisedIp !== null && !UdpEndpoint::isUnicast($advertisedIp))
            || ($advertisedPort !== null && ($advertisedPort < 1 || $advertisedPort > 65535))
            || $localPort < 0 || $localPort > 65535 || $serverPort < 1 || $serverPort > 65535
            || !preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D', $this->domain)
            || !preg_match('/^[a-zA-Z0-9_.!~+%-]+$/D', $username)
            || !is_finite($t1) || $t1 < 0.05 || $t1 > 4 || !is_finite($progressTimeout) || $progressTimeout <= 0) {
            throw new InvalidArgumentException('Invalid SIP endpoint or timers');
        }
        $this->transport = $transport ?? new UdpTransport();
        $this->prepared = new WeakMap();
        $this->identity = "<sip:$username@{$this->domain}>";
        $this->registrationId = $this->newId();
        $this->registrationTag = self::tag();
    }

    private function __clone() {}

    public function getLocalIp(): ?string { return $this->local?->ip; }
    public function getLocalPort(): ?int { return $this->local?->port; }
    public function getServerIp(): ?string { return $this->peer?->ip; }
    public function getServerPort(): int { return $this->serverPort; }
    public function getAdvertisedIp(): ?string { return $this->advertised?->ip; }
    public function getAdvertisedPort(): ?int { return $this->advertised?->port; }

    public static function tag(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function newId(): string
    {
        return self::tag() . '@' . $this->domain;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }
        if (Coroutine::getCid() < 0) {
            throw new RuntimeException('Start the UA inside a Swoole coroutine');
        }
        if ($this->opening) {
            throw new RuntimeException('SIP transport is already opening');
        }
        $this->opening = true;
        $generation = ++$this->generation;
        try {
            $ip = $this->server;
            if (ip2long($ip) === false) {
                $resolved = System::gethostbyname($this->server, AF_INET, 5.0);
                if ($resolved === false || !UdpEndpoint::isUnicast($resolved)) {
                    throw new RuntimeException('No usable IPv4 address for SIP server ' . $this->server);
                }
                $ip = $resolved;
            }
            $peer = new UdpEndpoint($ip, $this->serverPort);
            if ($generation !== $this->generation) {
                throw new RuntimeException('SIP startup cancelled');
            }
            $local = $this->transport->open($this->localIp, $this->localPort, $peer);
            if ($generation !== $this->generation) {
                $this->transport->close();
                throw new RuntimeException('SIP startup cancelled');
            }
            $advertised = new UdpEndpoint($this->advertisedIp ?? $local->ip, $this->advertisedPort ?? $local->port);
            $this->local = $local;
            $this->peer = $peer;
            $this->advertised = $advertised;
            $this->sentBy = "{$advertised->ip}:{$advertised->port}";
            $this->contact = "<sip:{$this->username}@{$this->sentBy}>";
            $this->running = true;
        } finally {
            $this->opening = false;
        }
        $receiver = Coroutine::create(function () use ($generation): void {
            try {
                $burst = 0;
                while ($this->running && $generation === $this->generation) {
                    $packet = $this->transport->receive(0.05);
                    if (!$this->running || $generation !== $this->generation) {
                        break;
                    }
                    $now = hrtime(true) / 1e9;
                    if ($packet !== null) {
                        try {
                            $this->dispatch(SipParser::parse($packet), $now);
                        } catch (InvalidArgumentException $e) {
                            $this->trace('discarded malformed SIP message');
                        }
                    }
                    if (!$this->running || $generation !== $this->generation) {
                        break;
                    }
                    foreach ($this->transactions as $key => $tx) {
                        if (!$tx->tick($now, $this)) {
                            unset($this->transactions[$key]);
                        }
                    }
                    foreach ($this->serverReplies as $key => $entry) {
                        if ($now >= $entry[0]) {
                            unset($this->serverReplies[$key]);
                        }
                    }
                    if ($packet === null) {
                        $burst = 0;
                    } elseif (++$burst >= 32) {
                        $burst = 0;
                        Coroutine::sleep(0.001);
                    }
                }
            } catch (Throwable $e) {
                if ($generation === $this->generation && $this->running) {
                    $this->shutdown('SIP transport or dispatch failure');
                }
            }
        });
        if ($receiver === false || !$this->running) {
            $this->shutdown('Receiver unavailable');
            throw new RuntimeException('Unable to start SIP receiver');
        }
    }

    public function stop(): void
    {
        if ($this->opening) {
            ++$this->generation;
        }
        $this->shutdown('User agent stopped');
    }

    private function shutdown(string $reason): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        ++$this->generation;
        // Detach state before waking waiters that may immediately restart the UA.
        $transactions = $this->transactions;
        $calls = $this->calls;
        foreach ($this->prepared as $call => $unused) {
            $calls[$call->id] = $call;
        }
        $this->prepared = new WeakMap();
        $this->local = null;
        $this->peer = null;
        $this->advertised = null;
        $this->transactions = [];
        $this->calls = [];
        $this->serverReplies = [];
        $this->transport->close();
        foreach ($transactions as $tx) {
            $tx->abort($reason);
        }
        foreach ($calls as $call) {
            $call->ended($reason, null, true);
        }
    }

    public function register(float $timeout = 5.0, int $expires = 3600): SipMessage
    {
        if ($expires < 1) {
            throw new InvalidArgumentException('Invalid registration lifetime');
        }
        return $this->registration($timeout, $expires);
    }

    public function unregister(float $timeout = 5.0): SipMessage
    {
        return $this->registration($timeout, 0);
    }

    private function registration(float $timeout, int $expires): SipMessage
    {
        if (!$this->running) {
            throw new RuntimeException('Start the UA before registering');
        }
        if ($this->registering || !is_finite($timeout) || $timeout <= 0) {
            throw new RuntimeException('Invalid or concurrent registration');
        }
        $this->registering = true;
        $deadline = hrtime(true) / 1e9 + $timeout;
        $uri = 'sip:' . $this->domain;
        $auth = [];
        try {
            while (true) {
                $left = $deadline - hrtime(true) / 1e9;
                if ($left <= 0) {
                    throw new RuntimeException('REGISTER timeout');
                }
                $fields = array_merge([['Expires', (string) $expires]], $this->credentials($auth, 'REGISTER', $uri));
                $request = $this->request('REGISTER', $uri, $this->registrationId, $this->registrationTag,
                    $this->identity, ++$this->registrationSeq, $fields);
                $result = new Channel(1);
                $this->transact($request, function (?SipMessage $msg, string $error) use ($result): void {
                    if ($msg === null || $msg->status() >= 200) {
                        $result->push([$msg, $error]);
                    }
                }, $left);
                $reply = $result->pop(max(0.001, $deadline - hrtime(true) / 1e9));
                if ($reply === false) {
                    throw new RuntimeException('SIP timeout');
                }
                [$response, $error] = $reply;
                if ($response === null) {
                    throw new RuntimeException($error);
                }
                $status = $response->status();
                if ($status === 401 || $status === 407) {
                    $auth = $this->challenge($auth, $response);
                    continue;
                }
                if ($status >= 300) {
                    throw new RuntimeException('REGISTER failed with SIP ' . $status);
                }
                return $response;
            }
        } finally {
            $this->registering = false;
        }
    }

    /** @internal */
    public function challenge(array $previous, SipMessage $msg): array
    {
        $proxy = $msg->status() === 407;
        $header = $proxy ? 'Proxy-Authorization' : 'Authorization';
        if (isset($previous[$header])) {
            throw new RuntimeException('SIP authentication rejected (' . $msg->status() . ')');
        }
        $previous[$header] = DigestAuthenticator::fromChallenges($msg->values($proxy ? 'proxy-authenticate' : 'www-authenticate'));
        return $previous;
    }

    /** @internal Generate fresh credentials for each new request, including sequential proxy and UAS challenges. */
    public function credentials(array $challenges, string $method, string $uri): array
    {
        $fields = [];
        foreach ($challenges as $header => $digest) {
            $fields[] = [$header, $digest->authorize($this->username, $this->password, $method, $uri)];
        }
        return $fields;
    }

    public function createCall(
        string $destination, ?SdpSession $offer = null, ?string $mediaIp = null, int $mediaPort = 0,
        array $codecs = ['PCMA', 'PCMU'], int $ptime = 20, ?string $mediaBindIp = null,
        ?int $advertisedMediaPort = null
    ): SipCall {
        if (!str_starts_with($destination, 'sip:')) {
            $destination = 'sip:' . $destination . '@' . $this->domain;
        }
        $uri = SipDialog::uri($destination);
        if ($offer !== null) {
            if ($mediaIp !== null || $mediaPort !== 0 || $mediaBindIp !== null || $advertisedMediaPort !== null
                || $codecs !== ['PCMA', 'PCMU'] || $ptime !== 20) {
                throw new InvalidArgumentException('A complete SDP offer already specifies its media parameters');
            }
            return new SipCall($this, $uri, $offer);
        }
        if (!$this->running || $this->local === null || count($this->prepared) + count($this->calls) >= $this->maxCalls) {
            throw new RuntimeException("Start the UA before preparing a call; at most {$this->maxCalls} calls may hold endpoints");
        }
        $bindIp = $mediaBindIp ?? $this->local->ip;
        $address = $mediaIp ?? $bindIp;
        if (!UdpEndpoint::isUnicast($bindIp) || !UdpEndpoint::isUnicast($address)
            || ($advertisedMediaPort !== null && ($advertisedMediaPort < 1 || $advertisedMediaPort > 65535))) {
            throw new InvalidArgumentException('Specify a concrete media bind address and a valid advertised endpoint');
        }
        $payloads = [];
        foreach ($codecs as $codec) {
            $payloads[] = match ($codec) {
                'PCMA' => G711Decoder::PCMA, 'PCMU' => G711Decoder::PCMU,
                default => throw new InvalidArgumentException('Expected PCMA or PCMU')
            };
        }
        $socket = UdpEndpoint::bind($bindIp, $mediaPort);
        try {
            $bound = UdpEndpoint::local($socket);
            $sdp = new SdpSession($address, $advertisedMediaPort ?? $bound->port, $payloads, $ptime, 'inactive');
            $call = new SipCall($this, $uri, $sdp, $socket, $bound);
            $this->prepared[$call] = true;
            return $call;
        } catch (Throwable $error) {
            $socket->close();
            throw $error;
        }
    }

    /** @internal */
    public function attach(SipCall $call): void
    {
        if (!$this->running || (!isset($this->prepared[$call]) && count($this->prepared) + count($this->calls) >= $this->maxCalls)) {
            throw new RuntimeException('UA stopped or call limit reached');
        }
        unset($this->prepared[$call]);
        $this->calls[$call->id] = $call;
    }

    /** @internal */
    public function release(SipCall $call): void
    {
        unset($this->prepared[$call], $this->calls[$call->id]);
    }

    /** @internal */
    public function request(string $method, string $uri, string $id, string $tag, string $to, int $seq,
        array $fields = [], string $body = ''): SipMessage
    {
        $base = [
            ['Via', 'SIP/2.0/UDP ' . $this->sentBy . ';branch=z9hG4bK' . self::tag() . ';rport'],
            ['Max-Forwards', '70'], ['From', $this->identity . ';tag=' . $tag], ['To', $to],
            ['Call-ID', $id], ['CSeq', "$seq $method"]
        ];
        if (in_array($method, ['REGISTER', 'INVITE'], true)) {
            $base[] = ['Contact', $this->contact];
        }
        return new SipMessage("$method $uri SIP/2.0", array_merge($base, $fields), $body);
    }

    /** ACK for a negative final and CANCEL use the original transaction's URI and Via. */
    public function related(SipMessage $invite, string $method, string $to): SipMessage
    {
        $fields = [];
        foreach (['via', 'max-forwards', 'from', 'call-id', 'route'] as $key) {
            foreach ($invite->values($key) as $value) {
                $fields[] = [$key, $value];
            }
        }
        if ($method === 'ACK') {
            foreach (['authorization', 'proxy-authorization'] as $name) {
                foreach ($invite->values($name) as $value) {
                    $fields[] = [$name, $value];
                }
            }
        }
        $fields[] = ['to', $to];
        $fields[] = ['cseq', (int) $invite->header('cseq') . ' ' . $method];
        return new SipMessage($method . substr($invite->line, 6), $fields);
    }

    /** @internal */
    public function transact(SipMessage $request, Closure $notify, float $timeout = 3600.0): void
    {
        if (!$this->running || count($this->transactions) >= $this->maxTransactions) {
            throw new RuntimeException('UA stopped or transaction limit reached');
        }
        $key = $request->branch() . ' ' . $request->method();
        $tx = new SipTransaction($request, $notify, hrtime(true) / 1e9,
            $this->t1, $this->progressTimeout, $timeout);
        $this->transactions[$key] = $tx;
        try {
            if ($request->method() === 'CANCEL') {
                $invite = $this->transactions[$request->branch() . ' INVITE'] ?? null;
                $invite?->cancelling(hrtime(true) / 1e9);
            }
            if ($this->log) {
                $this->trace('send ' . $request->method() . ' ' . $request->status());
            }
            $this->sendPacket($tx->packet());
        } catch (Throwable $e) {
            unset($this->transactions[$key]);
            throw $e;
        }
    }

    /** @internal */
    public function sendPacket(string $packet): void
    {
        $this->transport->send($packet);
    }

    /** @internal */
    public function sendMessage(SipMessage $msg): void
    {
        $this->trace('send ' . $msg->method() . ' ' . $msg->status());
        $this->sendPacket($msg->wire());
    }

    private function trace(string $text): void
    {
        if ($this->log) {
            error_log('[SpechMedia SIP] ' . $text);
        }
    }

    private function dispatch(SipMessage $msg, float $now): void
    {
        $method = $msg->method();
        $key = $msg->branch() . ' ' . $method;
        $this->trace("recv $method " . $msg->status());
        if ($msg->status()) {
            $tx = $this->transactions[$key] ?? null;
            if ($tx !== null && $tx->matches($msg)) {
                $tx->response($msg, $this, $now);
            }
            return;
        }
        if ($method === 'ACK') {
            return;
        }
        $key = hash('sha256', implode("\n", [$msg->header('via'), $msg->header('call-id'), $msg->header('cseq'),
            $msg->header('from'), $msg->header('to')]));
        if (isset($this->serverReplies[$key])) {
            $this->sendPacket($this->serverReplies[$key][1]);
            return;
        }
        $call = $this->calls[$msg->header('call-id')] ?? null;
        $validBye = $method === 'BYE' && $call !== null && $call->matchesBye($msg);
        $status = $validBye ? '200 OK' : ($method === 'BYE' ? '481 Call/Transaction Does Not Exist' : '501 Not Implemented');
        $fields = [];
        foreach (['via', 'from', 'to', 'cseq', 'call-id'] as $name) {
            foreach ($msg->values($name) as $index => $value) {
                if ($name === 'via' && $index === 0) {
                    $parts = explode(',', $value, 2);
                    $via = $parts[0];
                    if (preg_match('/;rport(?=;|$)/i', $via)) {
                        $via = preg_replace('/;rport(?=;|$)/i', ';rport=' . $this->peer->port, $via);
                        $via .= ';received=' . $this->peer->ip;
                    } elseif (preg_match('/^SIP\/2\.0\/UDP\s+([^;: ]+)/i', $via, $sent)
                        && $sent[1] !== $this->peer->ip) {
                        $via .= ';received=' . $this->peer->ip;
                    }
                    $value = isset($parts[1]) ? $via . ',' . $parts[1] : $via;
                }
                if ($name === 'to' && SipMessage::parameter($value, 'tag') === '') {
                    $value .= ';tag=' . self::tag();
                }
                $fields[] = [$name, $value];
            }
        }
        $reply = new SipMessage('SIP/2.0 ' . $status, $fields);
        $wire = $reply->wire();
        if (count($this->serverReplies) >= 256) {
            array_shift($this->serverReplies);
        }
        $this->serverReplies[$key] = [$now + 64 * $this->t1, $wire];
        $this->sendPacket($wire);
        if ($validBye) {
            $call->ended('Remote BYE', $msg, false);
        }
    }
}
