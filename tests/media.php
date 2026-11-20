<?php
declare(strict_types=1);

use SpechMedia\Media\AudioBuffer;
use SpechMedia\Media\G711Decoder;
use SpechMedia\Media\RtpPacket;
use SpechMedia\Media\RtpReceiver;
use SpechMedia\Media\WavWriter;

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

function packet(int $sequence, string $payload = "\xd5", int $pt = 8, int $timestamp = 0): RtpPacket
{
    return RtpPacket::parse(pack('CCnNN', 0x80, $pt, $sequence, $timestamp, 0xabcdef01) . $payload);
}

$pcma = "\xd5\x55\xd4\x54\xc5\x45\xaa\x2a";
$pcmu = "\xff\x7f\xfe\x7e\x80\x00\x8f\x0f";
check(G711Decoder::decode($pcma, 8) === pack('v*', 8, -8, 24, -24, 264, -264, 32256, -32256), 'A-law reference samples');
check(G711Decoder::decode($pcmu, 0) === pack('v*', 0, 0, 8, -8, 32124, -32124, 16764, -16764), 'Mu-law reference samples');

$header = pack('CCnNN', 0x80, 0x88, 65535, 0xfedcba98, 0xabcdef01);
$parsed = RtpPacket::parse($header . $pcma);
check($parsed?->payloadType === 8 && $parsed->sequence === 65535 && $parsed->timestamp === 0xfedcba98
    && $parsed->ssrc === 0xabcdef01 && $parsed->payload === $pcma, 'RTP marker and full-width header fields');
$extensions = "\x00\x01\x02\x03\x04\x05\x06\x07";
$complex = "\xb2" . substr($header, 1) . pack('NNnn', 33, 44, 0xbede, 2) . $extensions . $pcma . "\x00\x00\x03";
check(RtpPacket::parse($complex)?->payload === $pcma, 'Skip two CSRCs, extension and padding');
check(RtpPacket::parse("\x90" . substr($header, 1) . pack('nn', 0, 0) . $pcma)?->payload === $pcma, 'Empty extension');
foreach ([
    '', substr($header, 0, 11), $header, "\x40" . substr($header, 1) . $pcma,
    "\x8f" . substr($header, 1) . $pcma,
    "\x90" . substr($header, 1) . "\x00\x01\x02",
    "\x90" . substr($header, 1) . pack('nn', 0xbede, 4096) . $pcma,
    "\xa0" . substr($header, 1) . $pcma . "\x00",
    "\xa0" . substr($header, 1) . $pcma . "\x09",
    "\xa0" . substr($header, 1) . $pcma . "\x0a",
] as $malformed) {
    check(RtpPacket::parse($malformed) === null, 'Malformed datagram must be rejected');
}
check(RtpPacket::parse($header . $pcma, 7) === null, 'Payload size ceiling');

$buffer = new AudioBuffer();
$receiver = new RtpReceiver($buffer);
foreach ([
    packet(65535, "\xd5"), packet(65534, "\xd4"), packet(1, "\x55"), packet(1, "\xd5"),
    packet(0, "\x54"), packet(65535, "\xd4"),
    packet(3, "\xc5"), packet(5, "\x45"), packet(4, "\xaa")
] as $datagram) {
    $receiver->receive($datagram);
}
check($buffer->pcm() === pack('v*', 24, 8, -24, -8), 'Initial reorder, duplicate rejection and sequence wrap');
$receiver->finishStream();
check($buffer->pcm() === pack('v*', 24, 8, -24, -8, 264, 32256, -264), 'Flush with sequence loss in received order');

$buffer = new AudioBuffer();
$receiver = new RtpReceiver($buffer);
foreach ([packet(1), packet(2, "\xd4"), packet(4, "\x55"), packet(25, "\xc5"), packet(3, "\x54")] as $datagram) {
    $receiver->receive($datagram);
}
check($buffer->pcm() === pack('v*', 8, 24, -8), 'Window advances past a hole and discards a stale packet');
$receiver->finishStream();
check($buffer->pcm() === pack('v*', 8, 24, -8, 264), 'Flush preserves newer packets');

$buffer = new AudioBuffer();
$receiver = new RtpReceiver($buffer);
$expected = '';
$timestamp = 0xfffffff0;
foreach ([80, 160, 240, 320, 480, 8000, 40] as $index => $samples) {
    $receiver->receive(packet($index, str_repeat("\xfe", $samples), 0, $timestamp));
    $expected .= str_repeat(pack('v', 8), $samples);
    $timestamp = ($timestamp + $samples) & 0xffffffff;
}
$receiver->receive(RtpPacket::parse(pack('CCnNN', 0x80, 0, 7, $timestamp, 4) . "\x00"));
$receiver->finishStream();
check($buffer->pcm() === $expected && $buffer->bytes() === strlen($expected), 'Variable ptime, timestamp wrap, PCM blocks and SSRC lock');

$buffer = new AudioBuffer(7);
$buffer->append(packet(1, "\xd5\xd4"));
$buffer->append(packet(2, "\xfe", 0));
check($buffer->bytes() === 6 && !$buffer->truncated(), 'Odd memory limit rounds down and exact fit does not truncate');
$buffer->append(packet(3, "\xaa\x2a"));
check($buffer->pcm() === pack('v*', 8, 24, 8) && $buffer->truncated(), 'Recording stops at complete samples');
$buffer = new AudioBuffer(4);
$buffer->append(packet(1, $pcmu, 0));
check($buffer->bytes() === 4 && $buffer->truncated() && $buffer->pcm() === pack('v*', 0, 0), 'Partial packet at the byte limit');

$file = tempnam(sys_get_temp_dir(), 'spechmedia-');
unlink($file);
try {
    check(!WavWriter::write($file, '') && !file_exists($file), 'No WAV for empty PCM');
    check(WavWriter::write($file, $expected), 'WAV written');
    $wav = file_get_contents($file);
    check(substr($wav, 0, 4) === 'RIFF' && substr($wav, 8, 8) === 'WAVEfmt ' && substr($wav, 36, 4) === 'data',
        'RIFF and chunk identifiers');
    $format = unpack('Vsize/vformat/vchannels/Vrate/VbyteRate/vblock/vbits', $wav, 16);
    check($format === ['size' => 16, 'format' => 1, 'channels' => 1, 'rate' => 8000,
        'byteRate' => 16000, 'block' => 2, 'bits' => 16], 'PCM WAV format');
    check(unpack('V', $wav, 4)[1] === strlen($wav) - 8 && unpack('V', $wav, 40)[1] === strlen($expected)
        && substr($wav, 44) === $expected, 'WAV sizes and audio bytes');
    check(!WavWriter::write($file, '') && file_get_contents($file) === $wav, 'Empty export leaves an existing WAV intact');
} finally {
    if (file_exists($file)) {
        unlink($file);
    }
}

echo "OK: G.711, RTP ordering, bounded PCM and WAV\n";
