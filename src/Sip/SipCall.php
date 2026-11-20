<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use Closure;
use LogicException;
use RuntimeException;
use Throwable;
use SpechMedia\Net\UdpEndpoint;
use SpechMedia\Sdp\SdpSession;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class SipCall
{
    public readonly string $id;
    private string $tag;
    private string $state = 'new';
    private string $reason = '';
    private int $seq = 0;
    private bool $cancelWanted = false;
    private bool $cancelSent = false;
    private bool $provisional = false;
    private ?SipMessage $inviteRequest = null;
    private ?SipDialog $dialog = null;
    private ?SdpSession $remote = null;
    private string $earlyTag = '';
    private ?SipMessage $primaryAck = null;
    private string $primaryKey = '';
    private array $forkAcks = [];
    private array $auth = [];
    private array $listeners = [];
    private array $events = [];
    private bool $dispatching = false;
    private Channel $end;

    public function __construct(private SipUserAgent $ua, private string $uri, private SdpSession $offer,
        private ?Socket $mediaSocket = null, private ?UdpEndpoint $mediaBind = null)
    {
        $this->id = $ua->newId();
        $this->tag = SipUserAgent::tag();
        $this->end = new Channel(1);
    }

    private function __clone() {}

    public function __destruct()
    {
        $this->mediaSocket?->close();
    }

    public function state(): string { return $this->state; }
    public function reason(): string { return $this->reason; }
    public function remoteSdp(): ?SdpSession { return $this->remote; }

    public function getLocalSdp(): SdpSession { return $this->offer; }
    public function getRemoteSdp(): ?SdpSession { return $this->remote; }
    public function getLocalMediaIp(): string { return $this->offer->address; }
    public function getLocalMediaPort(): int { return $this->offer->port; }
    public function getMediaBindIp(): ?string { return $this->mediaBind?->ip; }
    public function getMediaBindPort(): ?int { return $this->mediaBind?->port; }

    public function negotiatedPayloads(): array
    {
        return $this->remote?->commonPayloads($this->offer) ?? [];
    }

    /** Each listener receives (SipCall, ?SipMessage, string reason). */
    public function onRinging(Closure $listener): void { $this->listeners['ringing'] = $listener; }
    public function onProgress(Closure $listener): void { $this->listeners['progress'] = $listener; }
    public function onAnswer(Closure $listener): void { $this->listeners['answer'] = $listener; }
    public function onFailed(Closure $listener): void { $this->listeners['failed'] = $listener; }
    public function onHangup(Closure $listener): void { $this->listeners['hangup'] = $listener; }

    private function emit(string $event, ?SipMessage $msg, string $reason = ''): void
    {
        $listener = $this->listeners[$event] ?? null;
        if ($listener === null || (count($this->events) >= 16 && ($event === 'ringing' || $event === 'progress'))) {
            return;
        }
        $this->events[] = [$listener, $msg, $reason];
        if ($this->dispatching) {
            return;
        }
        $this->dispatching = true;
        Coroutine::create(function (): void {
            Coroutine::sleep(0.001);
            while ($this->events) {
                [$callback, $message, $detail] = array_shift($this->events);
                try {
                    $callback($this, $message, $detail);
                } catch (Throwable $e) {
                    error_log('SpechMedia SIP callback failed');
                }
            }
            $this->dispatching = false;
        });
    }

    public function invite(): void
    {
        if ($this->state !== 'new') {
            throw new LogicException('Call already used');
        }
        $this->ua->attach($this);
        $this->state = 'calling';
        try {
            $this->sendInvite();
        } catch (Throwable $e) {
            $this->ended($e->getMessage(), null, true);
            throw $e;
        }
    }

    private function sendInvite(): void
    {
        $fields = array_merge([['Content-Type', 'application/sdp']], $this->ua->credentials($this->auth, 'INVITE', $this->uri));
        $request = $this->ua->request('INVITE', $this->uri, $this->id, $this->tag, '<' . $this->uri . '>',
            ++$this->seq, $fields, $this->offer->wire());
        $this->inviteRequest = $request;
        $this->provisional = false;
        $this->earlyTag = '';
        $this->remote = null;
        $this->ua->transact($request, function (?SipMessage $msg, string $error) use ($request): void {
            try {
                $this->inviteResult($request, $msg, $error);
            } catch (Throwable $e) {
                $this->ended($e->getMessage(), $msg, true);
            }
        });
    }

    private function inviteResult(SipMessage $request, ?SipMessage $msg, string $error): void
    {
        if ($msg === null) {
            if ($this->provisional && $error === 'SIP timeout') {
                $this->hangup();
            }
            $this->ended($error, null, true);
            return;
        }
        $status = $msg->status();
        if ($status < 200) {
            $this->provisional = true;
            if ($this->cancelWanted) {
                $this->sendCancel();
            }
            if ($status === 180 || $status === 183) {
                $this->state = $this->cancelWanted ? 'cancelling' : 'proceeding';
                if ($msg->body !== '') {
                    try {
                        $this->remote = $this->readSdp($msg);
                        $this->earlyTag = SipMessage::parameter($msg->header('to'), 'tag');
                    } catch (\InvalidArgumentException $e) {
                        $this->remote = null;
                    }
                }
                $this->emit($status === 180 ? 'ringing' : 'progress', $msg);
            }
            return;
        }
        if ($status < 300) {
            $this->accepted($request, $msg);
            return;
        }
        if (($status === 401 || $status === 407) && !$this->cancelWanted) {
            $this->auth = $this->ua->challenge($this->auth, $msg);
            $this->sendInvite();
            return;
        }
        $cancelled = $this->cancelWanted && $status === 487;
        $this->ended($cancelled ? 'Cancelled' : 'INVITE failed with SIP ' . $status, $msg, !$cancelled);
    }

    private function readSdp(SipMessage $msg): SdpSession
    {
        if (strtolower(trim(explode(';', $msg->header('content-type'), 2)[0])) !== 'application/sdp') {
            throw new \InvalidArgumentException('Expected application/sdp');
        }
        return SdpSession::parse($msg->body);
    }

    private function accepted(SipMessage $request, SipMessage $msg): void
    {
        $seq = (int) $request->header('cseq');
        $remoteTag = SipMessage::parameter($msg->header('to'), 'tag');
        $key = $seq . '/' . $remoteTag;
        $cached = $key === $this->primaryKey ? $this->primaryAck : ($this->forkAcks[$key] ?? null);
        if ($cached !== null) {
            $this->ua->sendMessage($cached);
            return;
        }
        $dialog = new SipDialog($msg, $seq);
        $credentials = [];
        foreach (['authorization', 'proxy-authorization'] as $name) {
            foreach ($request->values($name) as $value) {
                $credentials[] = [$name, $value];
            }
        }
        $ack = $this->dialogRequest($dialog, 'ACK', $seq, $credentials);
        $this->ua->sendMessage($ack);
        if ($this->dialog !== null || $this->terminal() || $request !== $this->inviteRequest) {
            if (count($this->forkAcks) >= 16) {
                array_shift($this->forkAcks);
            }
            $this->sendBye($dialog, [], false);
            $this->forkAcks[$key] = $ack;
            return;
        }
        $this->primaryKey = $key;
        $this->primaryAck = $ack;
        $this->dialog = $dialog;
        $this->state = 'answered';
        if ($this->cancelWanted) {
            $this->hangup();
            return;
        }
        try {
            if ($msg->body !== '') {
                $this->remote = $this->readSdp($msg);
            } elseif ($this->earlyTag !== $remoteTag || $this->remote === null) {
                throw new RuntimeException('Missing SDP answer');
            }
            // A reserved inactive endpoint never processes RTP. Keep the signaling
            // dialog even if the peer ignores inactive or omits its direction.
            $signalingOnly = $this->mediaSocket !== null && $this->offer->direction === 'inactive';
            if (!$signalingOnly && !$this->remote->acceptsDirection($this->offer)) {
                throw new RuntimeException('Incompatible SDP answer direction');
            }
            if (!$this->negotiatedPayloads()) {
                throw new RuntimeException('No accepted G.711 audio stream');
            }
        } catch (Throwable $e) {
            $this->reason = $e->getMessage();
            $this->hangup();
            return;
        }
        $this->emit('answer', $msg);
    }

    private function dialogRequest(SipDialog $dialog, string $method, int $seq, array $extra): SipMessage
    {
        [$uri, $routes] = $dialog->routing();
        foreach ($routes as $route) {
            $extra[] = ['route', $route];
        }
        return $this->ua->request($method, $uri, $this->id, $this->tag, $dialog->to, $seq, $extra);
    }

    public function hangup(): void
    {
        if ($this->terminal() || $this->state === 'ending') {
            return;
        }
        if ($this->state === 'new') {
            $this->ended('Cancelled before INVITE', null, false);
            return;
        }
        try {
            if ($this->dialog !== null) {
                $this->state = 'ending';
                $this->sendBye($this->dialog, [], true);
            } else {
                $this->cancelWanted = true;
                $this->state = 'cancelling';
                if ($this->provisional) {
                    $this->sendCancel();
                }
            }
        } catch (Throwable $e) {
            $this->ended($e->getMessage(), null, true);
        }
    }

    private function sendCancel(): void
    {
        if (!$this->cancelSent) {
            $this->cancelSent = true;
            $cancel = $this->ua->related($this->inviteRequest, 'CANCEL', $this->inviteRequest->header('to'));
            // Only the final INVITE response settles the call.
            $this->ua->transact($cancel, static function (?SipMessage $msg, string $error): void {});
        }
    }

    private function sendBye(SipDialog $dialog, array $auth, bool $primary): void
    {
        [$uri] = $dialog->routing();
        $request = $this->dialogRequest($dialog, 'BYE', $dialog->nextSequence(), $this->ua->credentials($auth, 'BYE', $uri));
        $this->ua->transact($request, function (?SipMessage $msg, string $error) use ($dialog, $auth, $primary): void {
            $status = $msg?->status() ?? 0;
            if ($msg !== null && $status < 200) {
                return;
            }
            if ($status === 401 || $status === 407) {
                try {
                    $credentials = $this->ua->challenge($auth, $msg);
                    $this->sendBye($dialog, $credentials, $primary);
                    return;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
            if ($primary) {
                if ($status >= 300 && $error === '') {
                    $error = 'BYE failed with SIP ' . $status;
                }
                $detail = $error !== '' ? $error : $this->reason;
                $this->ended($detail !== '' ? $detail : 'Local BYE', $msg, $detail !== '');
            }
        });
    }

    /** @internal */
    public function matchesBye(SipMessage $msg): bool
    {
        return $this->dialog !== null && SipMessage::parameter($msg->header('to'), 'tag') === $this->tag
            && SipMessage::parameter($msg->header('from'), 'tag') === SipMessage::parameter($this->dialog->to, 'tag');
    }

    private function terminal(): bool
    {
        return $this->state === 'ended' || $this->state === 'failed';
    }

    /** @internal */
    public function ended(string $reason, ?SipMessage $msg, bool $failed): void
    {
        if (!$this->terminal()) {
            $this->reason = $reason;
            $this->state = $failed ? 'failed' : 'ended';
            $this->mediaSocket?->close();
            $this->mediaSocket = null;
            $this->mediaBind = null;
            $this->ua->release($this);
            $this->emit($failed ? 'failed' : 'hangup', $msg, $reason);
            $this->end->close();
        }
    }

    public function waitForEnd(float $timeout = -1.0): bool
    {
        if (!$this->terminal()) {
            $this->end->pop($timeout);
        }
        return $this->terminal();
    }
}
