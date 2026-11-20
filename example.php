<?php
declare(strict_types=1);

use SpechMedia\Sdp\SdpSession;
use SpechMedia\Sip\SipCall;
use SpechMedia\Sip\SipMessage;
use SpechMedia\Sip\SipUserAgent;
use Swoole\Coroutine;

function main(): void
{
    Coroutine::set(['hook_flags' => 0]);
    \Swoole\Coroutine\run(static function (): void {
        $sip = null;
        $registered = false;

        try {
            // 1. Substitua o domínio, o ramal e a senha pelos dados do seu servidor SIP.
            $sip = new SipUserAgent(
                domain: 'sip.exemplo.com',
                username: '1001',
                password: 'sua-senha',
                localIp: '0.0.0.0',
                localPort: positiveSetting('SIP_LOCAL_PORT', '5060', 65535),
                peerIp: '0.0.0.0',
                peerPort: positiveSetting('SIP_PEER_PORT', '5060', 65535)
            );
            $destination = '123';
            $seconds = positiveSetting('SIP_CALL_SECONDS', '10', 86400);

            // 2. Iniciar o transporte UDP.
            $sip->start();

            // 3. Registrar o ramal. Uma falha no REGISTER lança uma exceção.
            $registration = $sip->register(timeout: 5);
            $registered = true;
            echo "Ramal registrado. {$registration->line}\n";

            // 4. Oferecer áudio. A aplicação de mídia é responsável pela porta RTP.
            $offer = new SdpSession(
                address: requiredSetting('MEDIA_IP'),
                port: positiveSetting('MEDIA_PORT', '', 65535),
                payloads: [8, 0], // PCMA e PCMU
                ptime: 20
            );

            // 5. Criar a chamada usando o ramal registrado como origem.
            $call = $sip->createCall(destination: $destination, offer: $offer);

            // 6. Configurar os eventos antes do INVITE.
            $call->onRinging(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo "Telefone tocando (180 Ringing).\n";
            });

            $call->onProgress(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo 'Progresso SIP: ' . $message?->line . "\n";
            });

            $call->onAnswer(static function (SipCall $call, ?SipMessage $message, string $reason) use ($seconds): void {
                echo 'Chamada atendida. ' . $message?->line . "\n";
                echo "Call-ID: {$call->id}\n";
                $sdp = $call->remoteSdp();
                if ($sdp !== null) {
                    echo "SDP remoto:\n" . $sdp->wire();
                    echo 'Payloads negociados: ' . implode(', ', $call->negotiatedPayloads()) . "\n";
                }

                // Um BYE remoto interrompe a espera antes do prazo.
                if (!$call->waitForEnd(timeout: (float) $seconds)) {
                    $call->hangup();
                }
            });

            $call->onHangup(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo "Chamada encerrada. $reason\n";
            });

            $call->onFailed(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo 'Falha na chamada: ' . ($message?->line ?? 'sem resposta SIP') . " ($reason)\n";
            });

            // 7. Enviar o INVITE.
            echo "Chamando $destination. Call-ID: {$call->id}\n";
            $call->invite();

            // 8. Aguardar o encerramento local ou remoto.
            $call->waitForEnd();
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
        } finally {
            // 9. Remover o registro e liberar o transporte.
            try {
                if ($registered) {
                    $sip->unregister();
                }
            } catch (Throwable $error) {
                fwrite(STDERR, 'Falha no desregistro: ' . $error->getMessage() . "\n");
            } finally {
                $sip?->stop();
            }
        }
    });
}

function requiredSetting(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new RuntimeException('Defina ' . $key);
    }
    return $value;
}

function positiveSetting(string $key, string $fallback, int $maximum): int
{
    $value = getenv($key);
    $number = $value === false ? $fallback : $value;
    if (!preg_match('/^[0-9]+$/D', $number) || (int) $number < 1 || (int) $number > $maximum) {
        throw new RuntimeException("Defina $key entre 1 e $maximum");
    }
    return (int) $number;
}
