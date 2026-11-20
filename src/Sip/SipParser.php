<?php
declare(strict_types=1);

namespace SpechMedia\Sip;

use InvalidArgumentException;

final class SipParser
{
    public static function parse(string $packet): SipMessage
    {
        $end = strpos($packet, "\r\n\r\n");
        if ($end === false || $end > 16384 || strlen($packet) > 65507) {
            throw new InvalidArgumentException('SIP datagram exceeds limits or lacks header separator');
        }
        $lines = explode("\r\n", substr($packet, 0, $end));
        $start = array_shift($lines);
        if (!preg_match('/^(?:SIP\/2\.0 [1-6][0-9]{2} .*|[A-Z][A-Z0-9.!%*_+~-]* [^\s]+ SIP\/2\.0)$/D', $start)
            || count($lines) > 128) {
            throw new InvalidArgumentException('Invalid SIP start line or header count');
        }
        $fields = [];
        foreach ($lines as $line) {
            $last = count($fields) - 1;
            if ($last >= 0 && ($line[0] === ' ' || $line[0] === "\t")) {
                $fields[$last][1] .= ' ' . ltrim($line, " \t");
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                throw new InvalidArgumentException('Malformed header');
            }
            $fields[] = [rtrim(substr($line, 0, $colon), " \t"), trim(substr($line, $colon + 1), " \t")];
        }
        $body = substr($packet, $end + 4);
        $clean = [];
        $seen = [];
        foreach ($fields as [$name, $value]) {
            $key = SipMessage::canonical($name);
            if (in_array($key, ['call-id', 'from', 'to', 'cseq', 'content-length', 'content-type'], true)) {
                if (isset($seen[$key]) || $value === '') {
                    throw new InvalidArgumentException('Invalid singleton header');
                }
                $seen[$key] = true;
            }
            if ($key === 'content-length') {
                if (!preg_match('/^[0-9]+$/D', $value) || (int) $value > strlen($body)) {
                    throw new InvalidArgumentException('Invalid Content-Length');
                }
                $body = substr($body, 0, (int) $value);
            }
            if ($key === 'cseq') {
                if (!preg_match('/^([0-9]+)[ \t]+([A-Z][A-Z0-9.!%*_+~-]*)$/D', $value, $seq) || (int) $seq[1] >= 2147483648) {
                    throw new InvalidArgumentException('Invalid CSeq');
                }
                $value = (int) $seq[1] . ' ' . $seq[2];
            }
            $clean[] = [$name, $value];
        }
        $msg = new SipMessage($start, $clean, $body);
        foreach (['via', 'from', 'to', 'call-id', 'cseq'] as $required) {
            if ($msg->header($required) === '') {
                throw new InvalidArgumentException('Missing ' . $required);
            }
        }
        if (!$msg->status() && !str_starts_with($start, $msg->method() . ' ')) {
            throw new InvalidArgumentException('CSeq method mismatch');
        }
        return $msg;
    }
}
