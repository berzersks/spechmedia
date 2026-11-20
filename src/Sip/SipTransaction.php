<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use Closure;

/** One UDP client transaction. All times are monotonic seconds. */
final class SipTransaction
{
    private string $packet;
    private bool $invite;
    private bool $proceeding = false;
    private bool $completed = false;
    private float $interval;
    private float $next;
    private float $deadline;

    public function __construct(
        public readonly SipMessage $request,
        private Closure $notify,
        float $now,
        private float $t1,
        private float $progressTimeout,
        float $timeout
    ) {
        $this->packet = $request->wire();
        $this->invite = $request->method() === 'INVITE';
        $this->interval = $t1;
        $this->next = $now + $t1;
        $this->deadline = $now + min($timeout, 64 * $t1);
    }

    public function cancelling(float $now): void
    {
        if ($this->invite && !$this->completed) {
            $this->deadline = min($this->deadline, $now + 64 * $this->t1);
        }
    }

    public function matches(SipMessage $response): bool
    {
        return $response->header('call-id') === $this->request->header('call-id')
            && $response->header('cseq') === $this->request->header('cseq')
            && SipMessage::parameter($response->header('from'), 'tag') === SipMessage::parameter($this->request->header('from'), 'tag')
            && strcasecmp(explode(';', $response->header('via'), 2)[0], explode(';', $this->request->header('via'), 2)[0]) === 0;
    }

    public function response(SipMessage $response, SipUserAgent $ua, float $now): void
    {
        $status = $response->status();
        if ($status < 200) {
            if (!$this->completed) {
                if (!$this->proceeding) {
                    $this->proceeding = true;
                    if ($this->invite) {
                        $this->deadline = $now + $this->progressTimeout;
                    } else {
                        $this->interval = 4.0;
                        // The pending E timer fires on its existing schedule.
                    }
                }
                ($this->notify)($response, '');
            }
            return;
        }
        if ($this->invite && $status >= 300) {
            $ua->sendMessage($ua->related($this->request, 'ACK', $response->header('to')));
        }
        if ($this->completed && !($this->invite && $status < 300)) {
            return;
        }
        if (!$this->completed) {
            $this->completed = true;
            $retain = 5.0; // K = T4
            if ($this->invite) {
                $retain = $status < 300 ? 64 * $this->t1 : max(32.0, 64 * $this->t1);
            }
            $this->deadline = $now + $retain;
        }
        ($this->notify)($response, '');
    }

    /** False releases the transaction from the matching table. */
    public function tick(float $now, SipUserAgent $ua): bool
    {
        if ($now >= $this->deadline) {
            if (!$this->completed) {
                $this->abort('SIP timeout');
                if ($this->invite) {
                    $this->deadline = $now + max(32.0, 64 * $this->t1);
                    return true;
                }
            }
            return false;
        }
        if (!$this->completed && $now >= $this->next && !($this->invite && $this->proceeding)) {
            $ua->sendPacket($this->packet);
            $this->interval = $this->invite ? 2 * $this->interval : min(4.0, 2 * $this->interval);
            $this->next = $now + $this->interval;
        }
        return true;
    }

    public function abort(string $reason): void
    {
        if (!$this->completed) {
            $this->completed = true;
            ($this->notify)(null, $reason);
        }
    }
}
