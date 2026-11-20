<?php
declare(strict_types=1);

// Interpreter-only regression tests. No network sockets, no external registrar.
use SpechMedia\Sdp\SdpSession;
use SpechMedia\Sip\SipCall;
use SpechMedia\Sip\SipMessage;
use SpechMedia\Sip\SipParser;
use SpechMedia\Sip\SipTransport;
use SpechMedia\Sip\SipUserAgent;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SpechMedia\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
    }
});

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

final class MemoryTransport implements SipTransport
{
    private Channel $in;
    private Channel $out;

    public function open(): void
    {
        $this->in = new Channel(32);
        $this->out = new Channel(32);
    }

    public function send(string $data): void { $this->out->push($data); }
    public function close(): void { $this->in->close(); }
    public function receive(float $timeout): ?string { return $this->in->pop($timeout) ?: null; }
    public function inject(SipMessage $msg): void { $this->in->push($msg->wire()); }

    public function take(string $method): SipMessage
    {
        $packet = $this->out->pop(1.0);
        check(is_string($packet), "Missing $method");
        $msg = SipParser::parse($packet);
        check($msg->method() === $method, "Expected $method, received {$msg->line}");
        return $msg;
    }
}

function reply(SipMessage $request, int $code, array $fields = [], string $body = ''): SipMessage
{
    foreach (['via', 'call-id', 'from', 'cseq'] as $name) {
        $fields[] = [$name, $request->header($name)];
    }
    $to = $request->header('to');
    $fields[] = ['to', $to . (str_contains($to, ';tag=') ? '' : ';tag=remote')];
    return new SipMessage("SIP/2.0 $code Test", $fields, $body);
}

function checkRegistration(SipUserAgent $ua, MemoryTransport $wire): void
{
    $done = new Channel(1);
    Coroutine::create(static function () use ($ua, $done): void {
        $done->push($ua->register()->status());
    });
    $first = $wire->take('REGISTER');
    $challenge = 'Digest realm="pbx", nonce="nonce", qop="auth", algorithm=SHA-256';
    $wire->inject(reply($first, 407, [['Proxy-Authenticate', $challenge]]));
    $second = $wire->take('REGISTER');
    check($second->branch() !== $first->branch(), 'Digest retry needs a new branch');
    $wire->inject(reply($second, 401, [['WWW-Authenticate', $challenge]]));
    $third = $wire->take('REGISTER');
    check($third->header('proxy-authorization') !== '' && $third->header('authorization') !== '', 'Both credentials required');
    check(str_contains($third->header('proxy-authorization'), 'nc=00000002'), 'Proxy nonce count');
    preg_match('/cnonce="([^"]+)"/', $third->header('authorization'), $match);
    $expected = hash('sha256', hash('sha256', 'alice:pbx:fixture') . ':nonce:00000001:' . $match[1]
        . ':auth:' . hash('sha256', 'REGISTER:sip:pbx.test'));
    check(str_contains($third->header('authorization'), $expected), 'Incorrect Digest response');
    $wire->inject(reply($third, 200));
    check($done->pop(1) === 200, 'REGISTER result');
    Coroutine::create(static function () use ($ua, $done): void {
        $done->push($ua->unregister()->status());
    });
    $remove = $wire->take('REGISTER');
    check($remove->header('expires') === '0' && $remove->header('call-id') === $first->header('call-id'), 'Binding removal');
    $wire->inject(reply($remove, 200));
    check($done->pop(1) === 200, 'UNREGISTER result');
}

Coroutine::set(['hook_flags' => 0]);
Swoole\Coroutine\run(static function (): void {
    $wire = new MemoryTransport();
    $ua = new SipUserAgent('192.0.2.1', 5060, 'pbx.test', 'alice', 'fixture', '192.0.2.2', transport: $wire, t1: 0.1);
    $ua->start();
    try {
        checkRegistration($ua, $wire);
        $offer = new SdpSession('192.0.2.1', 1234);
        $answer = new SdpSession('192.0.2.2', 4321, [8], 30, 'recvonly');
        $fields = [['contact', '<sip:target@pbx.test>'], ['record-route', '<sip:r1;lr>, <sip:r2;lr>'], ['content-type', 'application/sdp']];
        $a = $ua->createCall('100', $offer);
        $b = $ua->createCall('200', $offer);
        $a->invite();
        $b->invite();
        $ia = $wire->take('INVITE');
        $ib = $wire->take('INVITE');
        $a->hangup();
        $wire->inject(reply($ia, 100));
        $cancel = $wire->take('CANCEL');
        check($ia->branch() === $cancel->branch() && $cancel->header('to') === $ia->header('to'), 'CANCEL identity');
        $wire->inject(reply($cancel, 200));
        $busy = reply($ib, 486);
        $wire->inject($busy);
        $wire->inject($busy);
        foreach ([1, 2] as $attempt) {
            $ack = $wire->take('ACK');
            check($ack->branch() === $ib->branch() && $ack->header('to') === $busy->header('to'), 'Negative ACK');
        }
        check($b->waitForEnd(1) && $b->state() === 'failed', 'Busy call');
        $ok = reply($ia, 200, $fields, $answer->wire());
        $wire->inject($ok);
        $ack = $wire->take('ACK');
        $bye = $wire->take('BYE');
        check($ack->branch() !== $ia->branch() && $ack->header('route') === '<sip:r2;lr>', 'Dialog ACK');
        check($bye->line === 'BYE sip:target@pbx.test SIP/2.0' && $bye->header('cseq') === '2 BYE', 'CANCEL race BYE');
        $wire->inject($ok);
        check($wire->take('ACK')->wire() === $ack->wire(), 'Retransmitted success ACK');
        $wire->inject(reply($bye, 200));
        check($a->waitForEnd(1) && $a->state() === 'ended', 'CANCEL/200 race completed');

        $c = $ua->createCall('300', $offer);
        $events = new Channel(4);
        $listener = static function (SipCall $call, ?SipMessage $msg, string $detail) use ($events): void {
            Coroutine::sleep(0.005);
            $events->push($msg->status());
        };
        $c->onRinging($listener);
        $c->onProgress($listener);
        $c->onAnswer($listener);
        $c->invite();
        $ic = $wire->take('INVITE');
        check($wire->take('INVITE')->wire() === $ic->wire(), 'UDP retransmission');
        $wire->inject(reply($ic, 180));
        $wire->inject(reply($ic, 183, $fields, $answer->wire()));
        $accepted = reply($ic, 200, $fields);
        $wire->inject($accepted);
        $wire->take('ACK');
        check($c->negotiatedPayloads() === [8] && $c->remoteSdp()?->port === 4321, 'Early SDP retained');
        foreach ([180, 183, 200] as $code) {
            check($events->pop(1.0) === $code, 'Callback order');
        }
        $remoteBye = new SipMessage('BYE sip:alice@192.0.2.1 SIP/2.0', [
            ['via', 'SIP/2.0/UDP 192.0.2.2;branch=z9hG4bKtest'], ['from', $accepted->header('to')],
            ['to', $ic->header('from')], ['call-id', $c->id], ['cseq', '99 BYE']
        ]);
        $wire->inject($remoteBye);
        $wire->inject($remoteBye);
        check($wire->take('BYE')->status() === 200 && $wire->take('BYE')->status() === 200, 'Remote BYE replay');
        check($c->waitForEnd(1) && $c->reason() === 'Remote BYE', 'Remote teardown');
        $packet = str_replace('Content-Length: ' . strlen($ic->body), 'l: 3', $ic->wire());
        check(SipParser::parse($packet)->body === substr($ic->body, 0, 3), 'Compact Content-Length');
        try {
            SipParser::parse(str_replace('l: 3', "l: 3\r\nContent-Length: 4", $packet));
            throw new RuntimeException('Accepted duplicate Content-Length');
        } catch (InvalidArgumentException $e) {
            // Expected ambiguity rejection.
        }
        $cancelled = $ua->createCall('350', $offer);
        $cancelled->invite();
        $pending = $wire->take('INVITE');
        $wire->inject(reply($pending, 180));
        $cancelled->hangup();
        $cancellation = $wire->take('CANCEL');
        $wire->inject(reply($cancellation, 200));
        $wire->inject(reply($pending, 487));
        check($wire->take('ACK')->branch() === $pending->branch(), '487 ACK');
        check($cancelled->waitForEnd(1) && $cancelled->reason() === 'Cancelled', 'Normal cancellation');

        $rejected = $ua->createCall('400', $offer);
        $rejected->invite();
        $original = $wire->take('INVITE');
        $challenge = [['WWW-Authenticate', 'Digest realm="pbx", nonce="n"']];
        $wire->inject(reply($original, 401, $challenge));
        check($wire->take('ACK')->branch() === $original->branch(), 'ACK challenge');
        $retry = $wire->take('INVITE');
        check($retry->header('authorization') !== '' && $retry->header('cseq') === '2 INVITE', 'Authenticated INVITE');
        $wire->inject(reply($retry, 401, $challenge));
        $wire->take('ACK');
        check($rejected->waitForEnd(1) && $rejected->state() === 'failed', 'Bounded authentication attempts');

        $timeout = new Channel(1);
        Coroutine::create(static function () use ($ua, $timeout): void {
            try {
                $ua->register(0.2);
                $timeout->push(false);
            } catch (RuntimeException $e) {
                $timeout->push($e->getMessage() === 'SIP timeout');
            }
        });
        $wire->take('REGISTER');
        check($timeout->pop(1.0) === true, 'Silent registrar timeout');
        echo "OK: offline signaling scenarios\n";
    } finally {
        $ua->stop();
    }
});
