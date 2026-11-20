<?php
declare(strict_types=1);

use SpechMedia\Sip\SipCall;
use SpechMedia\Sip\SipMessage;
use SpechMedia\Sip\SipUserAgent;
use Swoole\Coroutine;

function main(): void
{
    Coroutine::set(['hook_flags' => 0]);

    Co\run(static function (): void {
        $sip = null;
        $registered = false;


        $prevStat = null;

       go(function (){
         for ($ix = 0; $ix < 10; $ix++) {
             // Lê apenas a primeira linha do /proc/stat (total de todas as CPUs)
             $handle = fopen('/proc/stat', 'r');
             if (!$handle) {
                 return;
             }
             $line = fgets($handle);
             fclose($handle);

             // Extrai os valores numéricos: user, nice, system, idle, iowait, irq, softirq, steal
             preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches);
             if (empty($matches)) {
                 return;
             }

             array_shift($matches); // Remove o match completo da string
             $currentStat = array_map('intval', $matches);

             // Na primeira execução, apenas armazena a base de comparação
             if ($prevStat === null) {
                 $prevStat = $currentStat;
                 return;
             }

             // Calcula a diferença de ciclos desde o último tick (1 segundo atrás)
             $deltas = [];
             foreach ($currentStat as $i => $val) {
                 $deltas[$i] = $val - $prevStat[$i];
             }
             $prevStat = $currentStat;

             $totalDelta = array_sum($deltas);
             if ($totalDelta === 0) {
                 return;
             }

             // Índice 3 = idle, Índice 4 = iowait
             $idleDelta = $deltas[3] + $deltas[4];
             $cpuUsage = (($totalDelta - $idleDelta) / $totalDelta) * 100;

             echo sprintf("$ix - Uso de CPU: %.2f%%\n", $cpuUsage);
            Coroutine::sleep(1);
            echo microtime(true).PHP_EOL;

         }
       });

        try {
            // 1. Substitua o domínio, o ramal e a senha pelos dados do seu servidor SIP.
            $sip = new SipUserAgent(
                server: 'silva.spechshop.com',
                username: 'ramal',
                password: 'ramal'
            );
            $destination = '123';
            $seconds = positiveSetting('SIP_CALL_SECONDS', '10', 86400);

            // 2. Iniciar o transporte UDP.
            $sip->start();

            // 3. Registrar o ramal. Uma falha no REGISTER lança uma exceção.
            $registration = $sip->register();
            $registered = true;
            echo "Ramal registrado. {$registration->line}\n";

            // 4. Preparar a chamada. A porta UDP fica reservada, sem processamento RTP.
            $call = $sip->createCall($destination);
            echo "Porta reservada para mídia: " . $call->getLocalMediaPort() . "\n";

            // 5. Configurar os eventos antes do INVITE.
            $call->onRinging(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo "Telefone tocando (180 Ringing).\n";
            });

            $call->onProgress(static function (SipCall $call, ?SipMessage $message, string $reason): void {
                echo 'Progresso SIP: ' . $message?->line . "\n";
            });

            $call->onAnswer(static function (SipCall $call, ?SipMessage $message, string $reason) use ($seconds): void {
                echo 'Chamada atendida. ' . $message?->line . "\n";
                echo "Call-ID: {$call->id}\n";
                $sdp = $call->getRemoteSdp();
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
                //var_dump($call);
            });

            // 6. Enviar o INVITE.
            echo "Chamando $destination. Call-ID: {$call->id}\n";
            try {
                $call->invite();
            } catch (Throwable $error) {
                fwrite(STDERR, $error->getMessage() . "kkk \n");
            }

            // 7. Aguardar o encerramento local ou remoto.
            $call->waitForEnd();
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "kkk \n");
        } finally {
            // 8. Remover o registro e liberar o transporte.
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

function positiveSetting(string $key, string $fallback, int $maximum): int
{
    $value = getenv($key);
    $number = $value === false ? $fallback : $value;
    if (!preg_match('/^[0-9]+$/D', $number) || (int) $number < 1 || (int) $number > $maximum) {
        throw new RuntimeException("Defina $key entre 1 e $maximum");
    }
    return (int) $number;
}
